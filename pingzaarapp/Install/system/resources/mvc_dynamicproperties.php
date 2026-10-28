<?php
/**
 * Trait: DynamicProperties
 * About: Handles dynamic property assignment using PHP magic methods.
 */

trait DynamicProperties
{
    private array $properties = [];

    /**
     * Retrieves a property value dynamically.
     * 
     * @param string $name
     * @return mixed|null
     */
    public function __get(string $name)
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Sets a property value dynamically.
     * 
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->properties[$name] = $value;
    }

    /**
     * Checks if a property is set.
     * 
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    /**
     * Unsets a property dynamically.
     * 
     * @param string $name
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->properties[$name]);
    }
}
