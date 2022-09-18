<?php

namespace ChrisPenny\DataObjectToFixture\Helper;

/**
 * Shout out to Adrian Humphreys (@adrhumphreys) for providing this one.
 */
class KahnSorter
{

    private array $sortedNodes = [];

    private array $messages = [];

    private array $warnings = [];

    private bool $processed = false;

    /**
     * Example input:
     * Page depends on:
     * - Image
     * - TaxonomyTerm
     * - TaxonomyType
     *
     * TaxonomyTerm depends on:
     * - TaxonomyType
     *
     * TaxonomyType and Image have no dependencies
     *
     * [
     *   'Page' => [
     *     'SilverStripe\Assets\Image',
     *     'SilverStripe\Taxonomy\TaxonomyTerm',
     *     'SilverStripe\Taxonomy\TaxonomyType',
     *   ],
     *   'SilverStripe\Taxonomy\TaxonomyTerm' => [
     *     'SilverStripe\Taxonomy\TaxonomyType',
     *   ],
     *   'SilverStripe\Taxonomy\TaxonomyType' => [],
     *   'SilverStripe\Assets\Image' => [],
     * ]
     */
    public function process(array $relationshipMap): array
    {
        // Reset everything before we kick off
        $this->sortedNodes = [];
        $this->messages = [];
        $this->warnings = [];
        // Before we can process our relationship map, we need to transfer them into nodes that contain more useful info
        $nodes = [];

        foreach ($relationshipMap as $key => $dependencies) {
            $nodes[$key] = [
                'name' => $key,
                'dependencies' => $dependencies ?? [],
                'count' => 0,
            ];

            // We do this since we want to support unspecified dependencies
            foreach ($dependencies as $dependency) {
                // A more informed version of it has been set
                if (isset($nodes[$dependency])) {
                    continue;
                }

                $nodes[$dependency] = [
                    'name' => $dependency,
                    'dependencies' => [],
                    'count' => 0,
                ];
            }
        }

        foreach ($nodes as $node) {
            $name = $node['name'];
            $edges = [];

            if (is_array($node['dependencies'])) {
                foreach ($node['dependencies'] as $edge) {
                    $edges[] = $edge;
                }
            }

            // Simple message, just as an FYI (it's not a warning)
            $this->messages[] = sprintf(
                '[Dependency resolution] %s depends on [%s]',
                $name,
                implode(',', $edges)
            );
        }

        // Now that all of our nodes have been put into the structure we require, we can start processing them
        $pending = [];

        // Loop through each of our nodes
        foreach ($nodes as $node) {
            // Loop through each dependency that this node has specified it depends on
            foreach ($node['dependencies'] as $dependency) {
                // Update the count of that dependency (marking it something else depends on it)
                $nodes[$dependency]['count'] += 1;
            }
        }

        // Loop through each node again
        foreach ($nodes as $node) {
            // In order for the sorter to work, we have to have at least one dependency that had no other nodes
            // depending on it
            // Skip any nodes that were marked as being depended on by another
            if ($node['count'] > 0) {
                continue;
            }

            // Any nodes with no other nodes depending on it can be processed (in any order between them) first
            $pending[] = $node;
        }

        $sortedNodes = [];

        // We're going to continue adding nodes to our $pending stack
        while (count($pending) > 0) {
            // Remove the first node from the $pending array
            $currentNode = array_shift($pending);
            // Add it to our $sortedNodes array
            $sortedNodes[] = $currentNode['name'];

            // We don't need to do anything else here if this node has no dependencies
            if (!is_array($currentNode['dependencies'])) {
                continue;
            }

            // Now that we have process this current node, we can go into each of its dependencies and mark that
            // dependencies count as minus 1 from its previous total (as we know we just processed one of the items that
            // dependended on it)
            foreach ($currentNode['dependencies'] as $dependency) {
                // Reduce the active count of dependent nodes on this dependency by 1
                $nodes[$dependency]['count'] -= 1;

                // If there are still other nodes that depend on this node, then we can't yet add it to our $pending
                // stack
                if ($nodes[$dependency]['count'] > 0) {
                    continue;
                }

                // This node is now ready to be processed as well
                $pending[] = $nodes[$dependency];
            }
        }

        // Finally, let's loop through our nodes one more time, to see if any nodes could not be processed. This would
        // indicate that there is a dependency loop in there somewhere (and so, they can't be sorted)
        foreach ($nodes as $node) {
            // This node was processed fine
            if ($node['count'] === 0) {
                continue;
            }

            // This node was not processed
            $this->warnings[] = sprintf(
                'Node `%s` has `%s` left over dependencies, and so could not be sorted',
                $node['name'],
                $node['count']
            );

            // We'll still add this node (so that it can be represented in our fixture), but with the warning above, it
            // might require some developer action
            $sortedNodes[] = $node['name'];
        }

        $this->sortedNodes = array_reverse($sortedNodes);
        $this->processed = true;

        return $this->sortedNodes;
    }

    public function hasProcessed(): bool
    {
        return $this->processed;
    }

    public function getSortedNodes(): array
    {
        return $this->sortedNodes;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

}
