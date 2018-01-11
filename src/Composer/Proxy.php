<?php

namespace Covex\Composer;

use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;

/**
 * @author Andrey F. Mindubaev
 */
class Proxy
{
    /**
     * @var object
     */
    private $object;

    /**
     * @var EventDispatcher
     */
    private $eventDispacher;

    /**
     * @var \ReflectionClass
     */
    private $reflection;

    /**
     * @var \ReflectionProperty[]
     */
    private $properties = [ ];

    /**
     * @var \ReflectionMethod[]
     */
    private $methods = [ ];

    /**
     * @var string
     */
    private $event;

    /**
     * @var array
     */
    private $subscribes = [ ];

    public function __construct($object, EventDispatcher $eventDispatcher = null)
    {
        if (!is_object($object)) {
            throw new \LogicException("Object must be object!");
        }
        $this->object = $object;
        $this->reflection = new \ReflectionClass(get_class($object));

        if (!is_null($eventDispatcher)) {
            $this->setEventDispatcher($eventDispatcher);
        }
    }

    /**
     * @param string $event
     * @param array  $subscribes
     */
    public function subscribe($event, array $subscribes)
    {
        if (is_null($this->eventDispacher)) {
            throw new \RuntimeException("EventDispatcher must be set");
        }
        if (!is_string($event)) {
            throw new \RuntimeException("EventName must be a string");
        }
        $this->event = $event;
        $this->subscribes = $subscribes;
    }

    /**
     * @param EventDispatcher $eventDispatcher
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispacher = $eventDispatcher;
    }

    public function __call($name, $arguments)
    {
        if (in_array($name, $this->subscribes)) {
            $eventName = "flex-" . strtolower($this->event) . "-" . $name;
        } else {
            $eventName = null;
        }

        $method = $this->getMethod($name);
        if ($method) {
            if (!is_null($eventName)) {
                $preEvent = new Event("pre-" . $eventName, [ "arguments" => $arguments ]);
                $this->eventDispacher->dispatch($preEvent->getName(), $preEvent);
            }
            $return = $method->invokeArgs($this->object, $arguments);
            if (!is_null($eventName)) {
                $postEvent = new Event("post-" . $eventName, [ "arguments" => $arguments, "return" => $return ]);
                $this->eventDispacher->dispatch($postEvent->getName(), $postEvent);
            }
        } else {
            $return = null;
        }
        return $return;
    }

    public function __get($name)
    {
        $property = $this->getProperty($name);
        if (!$property) {
            $value = null;
        } else {
            $value = $property->getValue($this->object);
        }
        return $value;
    }

    public function __set($name, $value)
    {
        $property = $this->getProperty($name);
        if ($property) {
            $property->setValue($this->object, $value);
        }
    }

    public function __isset($name)
    {
        return $this->getProperty($name) ? true : false;
    }

    /**
     * @param string $name
     *
     * @return \ReflectionProperty|null
     */
    private function getProperty($name)
    {
        if (isset($this->properties[$name])) {
            $property = $this->properties[$name];
        } elseif ($this->reflection->hasProperty($name)) {
            $property = $this->properties[$name] = $this->reflection->getProperty($name);
            if ($property->isPrivate() || $property->isProtected()) {
                $property->setAccessible(true);
            }
        } else {
            trigger_error("Property $name doesn't exists.", E_USER_ERROR);
            $property = null;
        }
        return $property;
    }

    /**
     * @param $name
     *
     * @return \ReflectionMethod|null
     */
    private function getMethod($name)
    {
        if (isset($this->methods[$name])) {
            $method = $this->methods[$name];
        } elseif ($this->reflection->hasMethod($name)) {
            $method = $this->methods[$name] = $this->reflection->getMethod($name);
            if ($method->isPrivate() || $method->isProtected()) {
                $method->setAccessible(true);
            }
        } else {
            trigger_error("Method $name doesn't exists.", E_USER_ERROR);
            $method = null;
        }
        return $method;
    }
}
