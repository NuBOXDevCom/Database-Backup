<?php

namespace Tests;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * The base URL to use while testing the project.
     *
     * @var string
     */
    protected $baseUrl = 'http://localhost';

    public function setUp()
    {
        parent::setUp();
    }
}
