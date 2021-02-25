<?php

namespace ChrisPenny\DataObjectToFixture\Helper;

/**
 * Shout out to Adrian Humphreys (@adrhumphreys) for providing this one.
 */
class KahnSorter
{

    /**
     * @var array[]
     */
    private $nodes = [];

    /**
     * @var array
     */
    private $messages = [];

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
     * @param array[] $nodes
     */
    public function __construct(array $nodes)
    {
        foreach ($nodes as $key => $dependencies) {
            $this->nodes[$key] = [
                'name' => $key,
                'dependencies' => $dependencies ?? [],
                'count' => 0,
            ];

            // We do this since we want to support unspecified dependencies
            foreach ($dependencies as $dependency) {
                // A more informed version of it has been set
                if (isset($this->nodes[$dependency])) {
                    continue;
                }

                $this->nodes[$dependency] = [
                    'name' => $dependency,
                    'dependencies' => [],
                    'count' => 0,
                ];
            }
        }

        foreach ($this->nodes as $node) {
            $name = $node['name'];
            $edges = [];

            if (is_array($node['dependencies'])) {
                foreach ($node['dependencies'] as $edge) {
                    $edges[] = $edge;
                }
            }

            $this->messages[] = sprintf(
                '[Dependency resolution] %s depends on [%s]',
                $name,
                implode(',', $edges)
            );
        }
    }

    public function sort(): array
    {
        $pending = [];

        foreach ($this->nodes as $node) {
            foreach ($node['dependencies'] as $dependency) {
                $this->nodes[$dependency]['count'] += 1;
            }
        }

        foreach ($this->nodes as $node) {
            if ($node['count'] === 0) {
                $pending[] = $node;
            }
        }

        $output = [];

        while (count($pending) > 0) {
            $currentNode = array_pop($pending);
            $output[] = $currentNode['name'];

            if (is_array($currentNode['dependencies'])) {
                foreach ($currentNode['dependencies'] as $dependency) {
                    $this->nodes[$dependency]['count'] -= 1;
                    if ($this->nodes[$dependency]['count'] === 0) {
                        $pending[] = $this->nodes[$dependency];
                    }
                }
            }
        }

        foreach ($this->nodes as $node) {
            if ($node['count'] !== 0) {
                $this->messages[] = sprintf(
                    'Node `%s` has `%s` left over dependencies',
                    $node['name'],
                    $node['count']
                );
            }
        }

        return array_reverse($output);
    }

}
