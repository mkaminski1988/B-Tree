<?php
/**
 * 3-Way B-Tree
 *
 * To do:
 * - Generalize for n-way trees
 * - Add delete and compact functions
 *
 * @author Mike Kaminski <michael.w.kaminski@gmail.com>
 * @since 5/7/2014
 */
namespace Kaminski\BTree;

use \OutOfRangeException;

class BTree
{
    /**
     * @var Node
     */
    private $rootNode;

    /**
     * The number of child nodes
     * @var int
     */
    private $order;

    /**
     * @var StoreInterface
     */
    private $store;

    /**
     * @param StoreInterface $store
     */
    public function __construct(StoreInterface $store)
    {
        $this->order = $store->getMaxKeys();
        $this->store = $store;
        $this->rootNode = $this->store->getRootNode();
    }

    /**
     * @param $key
     * @param $value
     */
    public function put($key, $value)
    {
        $left = $this->rootNode;

        $right = $this->insert($left, $key, $value);

        if ($right !== null) {

            $this->store->allocateNode($right);
            $third = new Node();
            $this->store->allocateNode($third);

            //Split root
            $n = new Node();
            $n->keys = array_splice($left->keys, 1, 1);
            $this->store->writeRootNode($n);

            $this->store->writeChildNode($n, 0, $left);
            $this->store->writeChildNode($n, 1, $right);
            $this->store->writeChildNode($n, 2, $third);

            $this->rootNode = $this->store->getRootNode();
        }
    }

    /**
     * @param $key
     * @return Entry|null
     */
    public function find($key)
    {
        return $this->search($this->rootNode, $key);
    }

    /**
     * @param $from
     * @param $to
     * @return Entry[]
     */
    public function getKeyRange($from, $to)
    {
        return $this->traverse($this->store->getRootNode(), $from, $to);
    }

    /**
     * @param Node $node
     * @param $key
     * @return Entry|null
     */
    private function search(Node $node, $key)
    {
        $key_count = count($node->keys);

        if ($key_count === 0) {
            return null;
        }

        for ($i = 0; $i < $key_count; $i++) {
            if ($key === $node->keys[$i]->key) {
                return $node->keys[$i];
            }
        }

        if (count($node->children) > 0) {
            for ($i = 0; $i <= $key_count; $i++) {
                if (($i === $key_count) || $key < $node->keys[$i]->key) {

                    $result = $this->search($this->store->getChildNode($node, $i), $key);

                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Split node returning new second node
     * @param Node $node
     * @return Node
     */
    private function split(Node $node)
    {
        $new_root = new Node();
        $new_root->keys = array_splice($node->keys, 2);
        $new_root->children = count($node->children) > 2 ? array_splice($node->children, 2) : array();

        return $new_root;
    }

    /**
     * @param Node $node
     * @param $key
     * @param $value
     * @return Node|null
     */
    private function insert(Node $node, $key, $value)
    {
        //Update node if key already exists
        foreach ($node->keys as $index => $entry) {
            if ($entry->key === $key) {
                $node->keys[$index]->value = $value;
                $this->store->writeNode($node);
                return null;
            }
        }

        if (count($node->children) === 0) { //Insert into leaf node

            $count = count($node->keys);

            for ($i = 0; $i < $count; $i++) {
                if ($key < $node->keys[$i]->key) {
                    break;
                }
            }

            $this->insertAt($node->keys, $i, new Entry($key, $value));

        } else { //Search non-leaf node

            $key_count = count($node->keys);

            for ($i = 0; $i < $key_count; $i++) {

                if ($key < $node->keys[$i]->key) {

                    $child = $this->store->getChildNode($node, $i);

                    $result = $this->insert($child, $key, $value);

                    if ($result !== null) {
                        $this->store->allocateNode($result);
                        $this->insertAt($node->children, $i + 1, $result->offset);
                        $node->keys = array_merge($node->keys, array_splice($child->keys, 1, 1));
                        sort($node->keys);
                        $this->store->writeNode($child);
                    }

                    break;
                }
            }

            if ($i === count($node->keys)) {
                $child = $this->store->getChildNode($node, $i);
                $result = $this->insert($child, $key, $value);
                if ($result !== null) {
                    $this->store->allocateNode($result);
                    $this->store->writeChildNode($node, $i + 1, $result);
                    $node->keys = array_merge($node->keys, array_splice($child->keys, 1, 1));
                    $this->store->writeNode($child);
                }
            }
        }

        if (count($node->keys) === $this->order) {
            return $this->split($node);
        } else {
            $this->store->writeNode($node);
        }

        return null;
    }

    /**
     * @param array $array
     * @param $index
     * @param $value
     */
    private function insertAt(array &$array, $index, $value)
    {
        $array_size = count($array);
        for ($j = $array_size; $j > $index; $j--) {
            $array[$j] = $array[$j - 1];
        }
        $array[$j] = $value;
    }

    /**
     * @param Node $node
     * @param $min_key
     * @param $max_key
     * @throws \OutOfRangeException
     * @return Entry[]
     */
    private function traverse(Node $node, $min_key, $max_key)
    {
        if ($min_key > $max_key) {
            throw new OutOfRangeException("Min can't be greater than max.");
        }

        $entries_found = array();

        $has_children = sizeof($node->children) > 0;

        $key_count = sizeof($node->keys);
        for ($i = 0; $i < $key_count; $i++) {
            if ($node->keys[$i]->key >= $min_key) {
                if ($has_children) {
                    $child_node = $this->store->getChildNode($node, $i);
                    $entries_found = array_merge(
                        $entries_found,
                        $this->traverse($child_node, $min_key, $max_key)
                    );
                }
                if ($node->keys[$i]->key <= $max_key) {
                    $entries_found[] = $node->keys[$i];
                }
            }
        }

        if ($has_children && $node->keys[$i - 1]->key < $max_key) {
            $child_node = $this->store->getChildNode($node, $i);
            $entries_found = array_merge(
                $entries_found,
                $this->traverse($child_node, $min_key, $max_key)
            );
        }

        return $entries_found;
    }
}