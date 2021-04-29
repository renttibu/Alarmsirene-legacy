<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class AlarmsireneValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateHMSecSirWMModule(): void
    {
        $this->validateModule(__DIR__ . '/../HM-Sec-Sir-WM');
    }

    public function testValidateHmIPASIRModule(): void
    {
        $this->validateModule(__DIR__ . '/../HmIP-ASIR');
    }
}