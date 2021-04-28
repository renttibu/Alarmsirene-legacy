<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/HmIP-ASIR
 */

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

trait AS_HMIPASIR_alarmProtocol
{
    private function UpdateAlarmProtocol(string $Message): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        if ($this->CheckMaintenanceMode()) {
            return;
        }

        $id = $this->ReadPropertyInteger('AlarmProtocol');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $timestamp = date('d.m.Y, H:i:s');
            $logText = $timestamp . ', ' . $Message;
            $logType = 0;
            $protocol = 'AP_UpdateMessages(' . $id . ', "' . $logText . '", ' . $logType . ');';
            @IPS_RunScriptText($protocol);
        }
    }
}