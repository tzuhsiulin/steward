<?php declare(strict_types=1);

namespace Lmc\Steward\Process;

use Assert\Assertion;
use Fhaculty\Graph\Graph;
use Fhaculty\Graph\Vertex;
use Graphp\Algorithms\Tree\OutTree;
use Lmc\Steward\Publisher\AbstractPublisher;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Set of Test processes.
 */
class ProcessSet implements \Countable
{
    /**
     * Array of wrapped Processes, indexed by testcase fully qualified name
     *
     * @var ProcessWrapper[]
     */
    protected $processes = [];

    /** @var AbstractPublisher|null */
    protected $publisher;

    /** @var Graph */
    protected $graph;

    /** @var OutTree */
    protected $tree;

    /**
     * Instantiate processSet to manage processes in different states,
     * If publisher is passed, it is used to publish process statuses after status changes.
     */
    public function __construct(AbstractPublisher $publisher = null)
    {
        $this->publisher = $publisher;

        $this->graph = new Graph();
    }

    public function setPublisher(AbstractPublisher $publisher): void
    {
        $this->publisher = $publisher;
    }

    /**
     * Get count of all processes in the set
     */
    public function count(): int
    {
        return count($this->processes);
    }

    /**
     * Add new process to the set.
     */
    public function add(ProcessWrapper $processWrapper): void
    {
        $className = $processWrapper->getClassName();
        if (isset($this->processes[$className])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Testcase with name "%s" was already added, make sure you don\'t have duplicate class names.',
                    $className
                )
            );
        }

        $this->processes[$className] = $processWrapper;

        $this->graph->createVertex($className);

        if ($this->publisher) {
            $this->publisher->publishResults($className, ProcessWrapper::PROCESS_STATUS_QUEUED, null);
        }
    }

    /**
     * Get array of processes in the set having given status
     *
     * @return ProcessWrapper[]
     */
    public function get(string $status): array
    {
        Assertion::choice($status, ProcessWrapper::PROCESS_STATUSES);

        $return = [];
        foreach ($this->processes as $className => $processWrapper) {
            if ($processWrapper->getStatus() === $status) {
                $return[$className] = $processWrapper;
            }
        }

        return $return;
    }

    /**
     * Set queued processes without delay as prepared
     *
     * @param OutputInterface $output Where list of dequeued and queued processes will be printed
     */
    public function dequeueProcessesWithoutDelay(OutputInterface $output): void
    {
        $queuedProcesses = $this->get(ProcessWrapper::PROCESS_STATUS_QUEUED);

        foreach ($queuedProcesses as $className => $processWrapper) {
            if (!$processWrapper->isDelayed()) {
                $output->writeln(
                    sprintf('Testcase "%s" is prepared to be run', $className),
                    OutputInterface::VERBOSITY_DEBUG
                );
                $processWrapper->setStatus(ProcessWrapper::PROCESS_STATUS_PREPARED);
            } else {
                $output->writeln(
                    sprintf(
                        'Testcase "%s" is queued to be run %01.1f minutes after testcase "%s" is finished',
                        $className,
                        $processWrapper->getDelayMinutes(),
                        $processWrapper->getDelayAfter()
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
            }
        }
    }

    /**
     * Build out-tree graph from defined Processes and their relations.
     *
     * @internal Should be called directly only in unit-testing
     */
    public function buildTree(): OutTree
    {
        if (!$this->tree) {
            $root = $this->graph->createVertex(0);

            // Create edges directed from the root node
            foreach ($this->processes as $className => $processWrapper) {
                $vertex = $this->graph->getVertex($className);

                if ($processWrapper->getDelayMinutes() === null) { // doesn't depend on anything => link it to the root
                    $root->createEdgeTo($vertex)->setWeight(0);
                } else { // is dependant => link it to its dependency
                    // Throw error if dependency is to not existing vertex
                    if (!$this->graph->hasVertex($processWrapper->getDelayAfter())) {
                        throw new \InvalidArgumentException(
                            sprintf(
                                'Testcase "%s" has @delayAfter dependency on "%s", but this testcase was not defined.',
                                $className,
                                $processWrapper->getDelayAfter()
                            )
                        );
                    }

                    $this->graph->getVertex($processWrapper->getDelayAfter())
                        ->createEdgeTo($vertex)
                        ->setWeight($processWrapper->getDelayMinutes());
                }
            }
        }

        $this->tree = new OutTree($this->graph);

        if (!$this->tree->isTree()) {
            throw new \InvalidArgumentException(
                sprintf('Cannot build tree graph from tests dependencies. Probably some cyclic dependency is present.')
            );
        }

        return $this->tree;
    }

    /**
     * Optimize order of processes using given strategy.
     */
    public function optimizeOrder(OptimizeOrderInterface $optimizeStrategy): void
    {
        $optimizedOrder = $optimizeStrategy->optimize($this->buildTree());

        // Sort the $optimizedOrder array to have the same order as corresponding array of processes
        // (so that the array could be passed to array_multisort())
        $sortingArray = [];
        foreach ($this->processes as $processClassName => $processObject) {
            $sortingArray[$processClassName] = $optimizedOrder[$processClassName];
        }

        // Sort processes descending according to corresponding values in $sortingArray
        array_multisort($sortingArray, SORT_DESC, SORT_NUMERIC, $this->processes);
    }

    /**
     * Get count of processes status
     */
    public function countStatuses(): array
    {
        $statusesCount = [];
        foreach (ProcessWrapper::PROCESS_STATUSES as $status) {
            $statusesCount[$status] = count($this->get($status));
        }

        return $statusesCount;
    }

    /**
     * Get result counts of done processes
     */
    public function countResults(): array
    {
        $done = $this->get(ProcessWrapper::PROCESS_STATUS_DONE);
        $doneClasses = [];
        $resultsCount = [
            ProcessWrapper::PROCESS_RESULT_PASSED => 0,
            ProcessWrapper::PROCESS_RESULT_FAILED => 0,
            ProcessWrapper::PROCESS_RESULT_FATAL => 0,
        ];

        // Retrieve names of done processes and count their results
        foreach ($done as $className => $processObject) {
            $doneClasses[] = $className;
            $resultsCount[$processObject->getResult()]++;
        }

        return $resultsCount;
    }

    /**
     * Mark all dependant processes of given process as failed
     *
     * @return ProcessWrapper[] Processes that has been failed
     */
    public function failDependants(string $className): array
    {
        $descendantProcesses = $this->getDependencyTree($className);

        $failedProcesses = [];
        foreach ($descendantProcesses as $processClassName => $processWrapper) {
            $failedProcesses[$processClassName] = $processWrapper;
            $processWrapper->setStatus(ProcessWrapper::PROCESS_STATUS_DONE);
        }

        return $failedProcesses;
    }

    /**
     * Get all wrapped processes that depends on process of given name.
     *
     * @return ProcessWrapper[]
     */
    protected function getDependencyTree(string $className): array
    {
        Assertion::notEmpty($this->tree, 'Cannot get dependency tree - the tree was not yet build using buildTree()');

        $descendants = $this->tree->getVerticesDescendant($this->graph->getVertex($className));

        /** @var ProcessWrapper[] $descendantProcesses */
        $descendantProcesses = [];

        /** @var Vertex $descendant */
        foreach ($descendants as $descendant) {
            $descendantProcesses[$descendant->getId()] = $this->processes[$descendant->getId()];
        }

        return $descendantProcesses;
    }
}
