<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class AlarmsireneValidationTest extends TestCaseSymconValidation
{
    public function testValidateAlarmsirene(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateAlarmsireneModule(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmsirene');
    }
}