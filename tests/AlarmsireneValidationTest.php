<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class AlarmsireneValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary_Alarmsirene(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateModule_Alarmsirene(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmsirene');
    }

    public function testValidateModule_HMSecSirWM(): void
    {
        $this->validateModule(__DIR__ . '/../HM-Sec-Sir-WM');
    }

    public function testValidateModule_HmIPASIR(): void
    {
        $this->validateModule(__DIR__ . '/../HmIP-ASIR');
    }
}