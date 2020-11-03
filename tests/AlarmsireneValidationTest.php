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

    public function testValidateAlarmsirene1Module(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmsirene 1');
    }

    public function testValidateAlarmsirene2Module(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmsirene 2');
    }

    public function testValidateAlarmsirene3Module(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmsirene 3');
    }
}