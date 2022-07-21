<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license     CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/Alarmsirene
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait AS_alarmSiren
{
    public function ResetSignallingAmount(): void
    {
        $this->SetTimerInterval('ResetSignallingAmount', (strtotime('next day midnight') - time()) * 1000);
        $this->SetValue('SignallingAmount', 0);
        $this->SendDebug(__FUNCTION__, 'Die Anzahl der Auslösungen wurde zurückgesetzt.', 0);
    }

    public function ToggleAlarmSiren(bool $State): bool
    {
        $acousticSignal = $this->ReadPropertyInteger('AlarmSirenAcousticSignal');
        $opticalSignal = $this->ReadPropertyInteger('AlarmSirenOpticalSignal');
        if ($acousticSignal == 0 && $opticalSignal == 0) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, es ist keine Alarmsirene vorhanden!', 0);
            return false;
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        $signallingAmountValue = $this->GetValue('SignallingAmount');
        // Switch alarm siren off
        if (!$State) {
            // Deactivate all timers
            $this->SetTimerInterval('DeactivatePreAlarm', 0);
            $this->SetTimerInterval('ExecuteMainAlarm', 0);
            $this->SetTimerInterval('ExecutePostAlarm', 0);
            $this->SetTimerInterval('DeactivateAlarmSiren', 0);
            $this->SetValue('AlarmSiren', false);
            $this->SetValue('Status', 0);
            $result = $this->SwitchAlarmSiren();
            if (!$result) {
                // Revert on failure
                $this->SetValue('AlarmSiren', $alarmSirenValue);
                $this->SetValue('Status', $statusValue);
                $this->SetValue('SignallingAmount', $signallingAmountValue);
                $text = 'Fehler, die Alarmsirene konnte nicht ausgeschaltet werden!';
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            } else {
                $text = 'Die Alarmsirene wurde ausgeschaltet.';
                if ($State != $alarmSirenValue) {
                    $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
                }
            }
        } // Switch alarm siren on
        else {
            if ($this->CheckMaintenanceMode()) {
                return false;
            }
            if ($this->CheckMuteMode()) {
                return false;
            }
            if (!$this->CheckSignallingAmount()) {
                return false;
            }
            if ($this->GetValue('Status') != 0) {
                return false;
            }
            $result = false;
            // Check pre alarm
            $usePreAlarm = $this->ReadPropertyBoolean('UsePreAlarm');
            if ($usePreAlarm) {
                $result = $this->ExecutePreAlarm();
            }
            // Check main alarm
            $useMainAlarm = $this->ReadPropertyBoolean('UseMainAlarm');
            if (!$usePreAlarm && $useMainAlarm) {
                $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
                if ($delay == 0) {
                    $result = $this->ExecuteMainAlarm(false);
                } else {
                    $this->SetTimerInterval('ExecuteMainAlarm', $delay * 1000);
                    $unit = 'Sekunden';
                    if ($delay == 1) {
                        $unit = 'Sekunde';
                    }
                    $text = 'Die Alarmsirene wird in ' . $delay . ' ' . $unit . ' eingeschaltet.';
                }
            }
            // Check post alarm
            $usePostAlarm = $this->ReadPropertyBoolean('UsePostAlarm');
            if (!$usePreAlarm && !$useMainAlarm && $usePostAlarm) {
                $result = $this->ExecutePostAlarm();
            }
        }
        if (isset($text)) {
            $this->SendDebug(__FUNCTION__, $text, 0);
        }
        return $result;
    }

    public function ExecutePreAlarm(): bool
    {
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
            return false;
        }
        if (!$this->CheckSignallingAmount()) {
            return false;
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        // Check main alarm
        if ($alarmSirenValue && $statusValue == 2) {
            return false;
        }
        $this->SetValue('AlarmSiren', true);
        $this->SetValue('Status', 1);
        $result = $this->SwitchAlarmSiren(1);
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Voralarm konnte nicht eingeschaltet werden!';
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Der Voralarm wurde eingeschaltet.';
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        $preAlarmDuration = $this->ReadPropertyInteger('PreAlarmDuration');
        $this->SetTimerInterval('DeactivatePreAlarm', $preAlarmDuration * 1000);
        $unit = 'Sekunden';
        if ($preAlarmDuration == 1) {
            $unit = 'Sekunde';
        }
        $this->SendDebug(__FUNCTION__, 'Der Voralarm wird in ' . $preAlarmDuration . ' ' . $unit . ' ausgeschaltet.', 0);
        // Main alarm
        if ($this->ReadPropertyBoolean('UseMainAlarm')) {
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay > $preAlarmDuration) {
                $unit = 'Sekunden';
                if ($delay == 1) {
                    $unit = 'Sekunde';
                }
                $this->SetTimerInterval('ExecuteMainAlarm', $delay * 1000);
                $this->SendDebug(__FUNCTION__, 'Der Hauptalarm wird in ' . $delay . ' ' . $unit . ' eingeschaltet.', 0);
            }
        }
        return $result;
    }

    public function DeactivatePreAlarm(): bool
    {
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        if (!$alarmSirenValue || $statusValue != 1) {
            return false;
        }
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        $this->SetValue('AlarmSiren', false);
        $this->SetValue('Status', 0);
        $result = $this->SwitchAlarmSiren();
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Voralarm konnte nicht ausgeschaltet werden!';
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Der Voralarm wurde ausgeschaltet.';
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        return $result;
    }

    public function ExecuteMainAlarm(bool $PanicAlarm): bool
    {
        $this->SetTimerInterval('ExecuteMainAlarm', 0);
        if (!$PanicAlarm) {
            if (!$this->ReadPropertyBoolean('UseMainAlarm')) {
                return false;
            }
            if ($this->CheckMuteMode()) {
                return false;
            }
            if (!$this->CheckSignallingAmount()) {
                return false;
            }
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $this->SetValue('AlarmSiren', true);
        $statusValue = $this->GetValue('Status');
        $this->SetValue('Status', 2);
        $result = $this->SwitchAlarmSiren(2);
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Hauptalarm konnte nicht eingeschaltet werden!';
            if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
                $text = 'Fehler, die Alarmsirene konnte nicht eingeschaltet werden!';
            }
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            if (!$PanicAlarm) {
                $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
            }
            $text = 'Der Hauptalarm wurde eingeschaltet.';
            if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
                $text = 'Die Alarmsirene wurde eingeschaltet.';
            }
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        $unit = 'Sekunden';
        $duration = $this->ReadPropertyInteger('MainAlarmDuration');
        if ($duration == 1) {
            $unit = 'Sekunde';
        }
        $text = 'Der Hauptalarm wird in ' . $duration . ' ' . $unit . ' ausgeschaltet.';
        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
            $text = 'Die Alarmsirene wird in ' . $duration . ' ' . $unit . ' ausgeschaltet.';
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        // Set timer
        if ($this->ReadPropertyBoolean('UsePostAlarm')) {
            $this->SetTimerInterval('ExecutePostAlarm', $duration * 1000);
            $text = 'Der Nachalarm wird in ' . $duration . ' ' . $unit . ' eingeschaltet.';
            $this->SendDebug(__FUNCTION__, $text, 0);
        } else {
            $this->SetTimerInterval('DeactivateAlarmSiren', $duration * 1000);
        }
        return $result;
    }

    public function ExecutePostAlarm(): bool
    {
        $this->SetTimerInterval('ExecutePostAlarm', 0);
        if (!$this->ReadPropertyBoolean('UsePostAlarm')) {
            return false;
        }
        if (!$this->ReadPropertyBoolean('UsePostAlarmOpticalSignal')) {
            return false;
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $this->SetValue('AlarmSiren', true);
        $statusValue = $this->GetValue('Status');
        $this->SetValue('Status', 3);
        $result = $this->SwitchAlarmSiren(3);
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Nachalarm konnte nicht eingeschaltet werden!';
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Der Nachalarm wurde eingeschaltet.';
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        $unit = 'Minuten';
        $duration = $this->ReadPropertyInteger('PostAlarmDuration');
        if ($duration == 1) {
            $unit = 'Minute';
        }
        $text = 'Der Nachalarm wird in ' . $duration . ' ' . $unit . ' ausgeschaltet.';
        $this->SendDebug(__FUNCTION__, $text, 0);
        $this->SetTimerInterval('DeactivateAlarmSiren', $duration * 60 * 1000);
        return $result;
    }

    #################### Private

    private function SwitchAlarmSiren(int $AlarmType = 0): bool
    {
        /*
         * $AlarmType
         * 0    = off
         * 1    = pre alarm
         * 2    = main alarm
         * 3    = post alarm
         */

        // Check maintenance mode only on activation
        if ($AlarmType != 0) {
            if ($this->CheckMaintenanceMode()) {
                return false;
            }
        }
        switch ($AlarmType) {
            case 1: # Pre alarm
                $debugText = 'Parameter: 1 = Voralarm';
                break;

            case 2: # Main alarm
                $debugText = 'Parameter: 2 = Hauptalarm';
                break;

            case 3: # Post alarm
                $debugText = 'Parameter: 3 = Nachalarm';
                break;

            default: # Alarm siren off
                $debugText = 'Parameter: 0 = Alarmsirene ausschalten';
        }
        $this->SendDebug(__FUNCTION__, $debugText, 0);
        $result = false;
        // Alarm siren
        switch ($AlarmType) {
            case 1: # Pre alarm
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    // Acoustic signal
                    if ($this->ReadPropertyBoolean('UsePreAlarmAcousticSignal')) {
                        $acousticSignal = $this->ReadPropertyInteger('AlarmSirenAcousticSignal');
                        $acousticSignalState = true;
                    }
                    // Optical signal
                    if ($this->ReadPropertyBoolean('UsePreAlarmOpticalSignal')) {
                        $opticalSignal = $this->ReadPropertyInteger('AlarmSirenOpticalSignal');
                        $opticalSignalState = true;
                    }
                }
                break;

            case 2: # Main alarm
                if ($this->ReadPropertyBoolean('UseMainAlarm')) {
                    // Acoustic signal
                    if ($this->ReadPropertyBoolean('UseMainAlarmAcousticSignal')) {
                        $acousticSignal = $this->ReadPropertyInteger('AlarmSirenAcousticSignal');
                        $acousticSignalState = true;
                    }
                    // Optical signal
                    if ($this->ReadPropertyBoolean('UseMainAlarmOpticalSignal')) {
                        $opticalSignal = $this->ReadPropertyInteger('AlarmSirenOpticalSignal');
                        $opticalSignalState = true;
                    }
                }
                break;

            case 3: # Post alarm
                if ($this->ReadPropertyBoolean('UsePostAlarm')) {
                    // Acoustic signal
                    $acousticSignal = $this->ReadPropertyInteger('AlarmSirenAcousticSignal');
                    $acousticSignalState = false;
                    // Optical signal
                    if ($this->ReadPropertyBoolean('UsePostAlarmOpticalSignal')) {
                        $opticalSignal = $this->ReadPropertyInteger('AlarmSirenOpticalSignal');
                        $opticalSignalState = true;
                    }
                }
                break;

            default: # Alarm siren off
                // Acoustic signal
                $acousticSignal = $this->ReadPropertyInteger('AlarmSirenAcousticSignal');
                $acousticSignalState = false;
                // Optical signal
                $opticalSignal = $this->ReadPropertyInteger('AlarmSirenOpticalSignal');
                $opticalSignalState = false;
        }
        // Alarm siren
        // Acoustic signal
        if (isset($acousticSignal) && isset($acousticSignalState)) {
            if ($acousticSignal != 0 && @IPS_ObjectExists($acousticSignal)) {
                $acousticResult = false;
                IPS_Sleep($this->ReadPropertyInteger('AlarmSirenAcousticSignalSwitchingDelay'));
                $response = @RequestAction($acousticSignal, $acousticSignalState);
                if (!$response) {
                    IPS_Sleep(self::DELAY_MILLISECONDS);
                    $response = @RequestAction($acousticSignal, $acousticSignalState);
                }
                if ($response) {
                    $acousticResult = true;
                }
            }
        }
        // Optical signal
        if (isset($opticalSignal) && isset($opticalSignalState)) {
            if ($opticalSignal != 0 && @IPS_ObjectExists($opticalSignal)) {
                $opticalResult = false;
                IPS_Sleep($this->ReadPropertyInteger('AlarmSirenOpticalSignalSwitchingDelay'));
                $response = @RequestAction($opticalSignal, $opticalSignalState);
                if (!$response) {
                    IPS_Sleep(self::DELAY_MILLISECONDS);
                    $response = @RequestAction($opticalSignal, $opticalSignalState);
                }
                if ($response) {
                    $opticalResult = true;
                }
            }
        }
        // Check result
        if (isset($acousticResult) && isset($opticalResult)) {
            if ($acousticResult && $opticalResult) {
                $result = true;
            }
        }
        if (isset($acousticResult) && !isset($opticalResult)) {
            if ($acousticResult) {
                $result = true;
            }
        }
        if (!isset($acousticResult) && isset($opticalResult)) {
            if ($opticalResult) {
                $result = true;
            }
        }
        return $result;
    }

    private function CheckSignallingAmount(): bool
    {
        $maximum = $this->ReadPropertyInteger('MainAlarmMaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $text = 'Abbruch, die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
                return false;
            }
        }
        return true;
    }
}