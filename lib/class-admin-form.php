<?php

/**
 * Form builder for WordPress admin pages.
 *
 * More field types will be added when needed.
 */
class AdminForm
{
    protected $name;
    protected $method;
    protected $action;

    private $tableRows = '';
    private $submitButton = '';

    public function __construct($name = 'form', $method = 'post', $action = '')
    {
        $this->name   = $name;
        $this->method = $method;
        $this->action = $action;
    }

    // Form elements =========================================================

    public function addTextField($title, $name, $value = '', $class = '', $required = false)
    {
        $this->tableRows .= $this->tableRow(
            $title,
            $name,
            $this->inputField('text', $name, $value, 'regular-text ' . $class),
            $required
        );

        return $this;
    }

    public function addSubmitButton($title)
    {
        $this->submitButton = <<<HTML
            <p class="submit">
                {$this->inputField('submit', 'submit', $title, 'button-primary')}
            </p>
HTML;

        return $this;
    }

    // Form generator ========================================================

    public function toString()
    {
        return <<<HTML
            <form name="{$this->name}" method="{$this->method}" action="{$this->action}">
                <table class="form-table">
                    {$this->tableRows}
                </table>
                {$this->submitButton}
            </form>
HTML;
    }

    public function __toString()
    {
        return $this->toString();
    }

    // Table helpers =========================================================

    protected function tableRow($title, $name, $value, $required = false)
    {
        $required = $required ? 'form-required' : '';
        return <<<HTML
            <tr class="{$required}" valign="top">
                <th scope="row"><label for="{$name}">{$title}</label></th>
                <td>{$value}</td>
            </tr>
HTML;
    }

    // Field helpers =========================================================

    protected function inputField($type, $name, $value = '', $class = '')
    {
        return <<<HTML
            <input type="{$type}" id="{$name}" name="{$name}" value="{$value}" class="{$class}">
HTML;
    }
}
