<?php

/**
 * WordPress options wrapper.
 *
 * Makes it simple to store all options for a plugin in a single serialized
 * array, thus saving entries in the options table.
 *
 * The $defaults array not only sets default values, it also describes what
 * key values are valid. Because of that, you must pass all possible keys
 * when constructing an Options object.
 */
class Options
{
    protected $name = null;
    protected $data = null;

    public function __construct($name, array $defaults = array())
    {
        $this->name = $name;
        // Remove invalid keys and add new defaults to the options array.
        $this->data = array_intersect_key(
            array_merge($defaults, get_option($this->name, $defaults)),
            $defaults
        );
        update_option($this->name, $this->data);
    }

    public function __get($option)
    {
        if (array_key_exists($option, $this->data)) {
            return $this->data[$option];
        } else {
            throw new DomainException("Unknown option: {$option}");
        }
    }

    public function __set($option, $value)
    {
        if (array_key_exists($option, $this->data)) {
            $this->data[$option] = $value;
            // Update right away. Could also be done in a __destruct method.
            update_option($this->name, $this->data);
        } else {
            throw new DomainException("Unknown option: {$option}");
        }
    }
}
