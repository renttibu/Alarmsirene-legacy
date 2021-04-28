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

trait AS_HMIPASIR_alarmSiren
{
    public function ResetSignallingAmount(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetValue('SignallingAmount', 0);
    }

    public function ToggleAlarmSiren(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        $signallingAmountValue = $this->GetValue('SignallingAmount');

        // Switch alarm siren off
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet.', 0);

            // Deactivate all timers
            $this->SetTimerInterval('ExecuteMainAlarm', 0);
            $this->SetTimerInterval('ExecutePostAlarm', 0);
            $this->SetTimerInterval('DeactivateAlarmSiren', 0);

            $this->SetValue('AlarmSiren', false);
            $this->SetValue('Status', 0);
            $result = $this->SwitchAlarmSiren(); # Alarm siren off

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

            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird eingeschaltet.', 0);

            $mode = 0;
            $result = false;

            // Check pre alarm
            $usePreAlarm = $this->ReadPropertyBoolean('UsePreAlarm');
            if ($usePreAlarm) {
                $mode = 1;
                $result = $this->ExecutePreAlarm();
            }

            // Check main alarm
            $useMainAlarm = $this->ReadPropertyBoolean('UseMainAlarm');
            if (!$usePreAlarm && $useMainAlarm) {
                $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
                if ($delay == 0) {
                    $mode = 2;
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
                $mode = 3;
                $result = $this->ExecutePostAlarm();
            }

            if ($mode != 0) {
                if (!$result) {
                    $text = 'Fehler, die Alarmsirene konnte nicht eingeschaltet werden!';
                    $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
                } else {
                    $text = 'Die Alarmsirene wurde eingeschaltet.';
                }
            }
        }
        if (isset($text)) {
            $this->SendDebug(__FUNCTION__, $text, 0);
        }

        return $result;
    }

    public function ExecutePreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        if ($this->CheckMaintenanceMode()) {
            return false;
        }

        if ($this->CheckMuteMode()) {
            return false;
        }

        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
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

        // Main alarm
        if ($this->ReadPropertyBoolean('UseMainAlarm')) {
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay == 0) {
                $this->ExecuteMainAlarm(false);
            } else {
                $this->SetTimerInterval('ExecuteMainAlarm', $delay * 1000);
            }
        } // Post alarm
        else {
            if ($this->ReadPropertyBoolean('UsePostAlarm')) {
                $this->ExecutePostAlarm();
            }
        }

        return $result;
    }

    public function ExecuteMainAlarm(bool $PanicAlarm): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

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
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            if (!$PanicAlarm) {
                $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
            }
            $text = 'Der Hauptalarm wurde eingeschaltet.';
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        $this->SendDebug(__FUNCTION__, $text, 0);

        $unit = 'Sekunden';
        $duration = $this->ReadPropertyInteger('MainAlarmDuration') * 1000;
        if ($duration == 1000) {
            $unit = 'Sekunde';
        }
        $text = 'Die Alarmsirene wird in ' . $duration / 1000 . ' ' . $unit . ' ausgeschaltet.';
        $this->SendDebug(__FUNCTION__, $text, 0);

        // Set timer
        if ($this->ReadPropertyBoolean('UsePostAlarm')) {
            $this->SetTimerInterval('ExecutePostAlarm', $duration);
        } else {
            $this->SetTimerInterval('DeactivateAlarmSiren', $duration);
        }

        return $result;
    }

    public function ExecutePostAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        $this->SetTimerInterval('ExecutePostAlarm', 0);

        if (!$this->ReadPropertyBoolean('UsePostAlarm')) {
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

        $this->SetTimerInterval('DeactivateAlarmSiren', $this->ReadPropertyInteger('PostAlarmDuration') * 60 * 1000);

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

        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        switch ($AlarmType) {
            case 1: # Pre alarm
                $debugText = 'Parameter: 1 Voralarm';
                break;

            case 2: # Pre alarm
                $debugText = 'Parameter: 2 Hauptalarm';
                break;

            case 3: # Post alarm
                $debugText = 'Parameter: 3 Nachalarm';
                break;

            default:
                $debugText = 'Parameter: 0 Alarmsirene ausschalten';
        }
        $this->SendDebug(__FUNCTION__, $debugText, 0);

        $result = true;

        // Alarm siren
        $id = $this->ReadPropertyInteger('AlarmSiren');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            switch ($AlarmType) {
                case 1: # Pre alarm
                    $acousticSignal = $this->ReadPropertyInteger('PreAlarmAcousticSignal');
                    $opticalSignal = $this->ReadPropertyInteger('PreAlarmOpticalSignal');
                    $durationUnit = 0;
                    $durationValue = $this->ReadPropertyInteger('PreAlarmDuration');
                    break;

                case 2: # Main alarm
                    $acousticSignal = $this->ReadPropertyInteger('MainAlarmAcousticSignal');
                    $opticalSignal = $this->ReadPropertyInteger('MainAlarmOpticalSignal');
                    $durationUnit = 0;
                    $durationValue = $this->ReadPropertyInteger('MainAlarmDuration');
                    break;

                case 3: # Post alarm
                    $acousticSignal = 0;
                    $opticalSignal = $this->ReadPropertyInteger('PostAlarmOpticalSignal');
                    $durationUnit = 0;
                    $durationValue = $this->ReadPropertyInteger('PostAlarmDuration') * 60;
                    break;

                default: # Alarm siren off
                    $acousticSignal = 0;
                    $opticalSignal = 0;
                    $durationUnit = 0;
                    $durationValue = 5;
            }

            $this->SendDebug(__FUNCTION__, 'Akustisches Signal: ' . $acousticSignal, 0);
            /*
             * 0    = DISABLE_ACOUSTIC_SIGNAL
             * 1    = FREQUENCY_RISING
             * 2    = FREQUENCY_FALLING
             * 3    = FREQUENCY_RISING_AND_FALLING
             * 4    = FREQUENCY_ALTERNATING_LOW_HIGH
             * 5    = FREQUENCY_ALTERNATING_LOW_MID_HIGH
             * 6    = FREQUENCY_HIGHON_OFF
             * 7    = FREQUENCY_HIGHON_LONGOFF
             * 8    = FREQUENCY_LOWON_OFF_HIGHON_OFF
             * 9    = FREQUENCY_LOWON_LONGOFF_HIGHON_LONGOFF
             * 10   = LOW_BATTERY
             * 11   = DISARMED
             * 12   = INTERNALLY_ARMED
             * 13   = EXTERNALLY_ARMED
             * 14   = DELAYED_INTERNALLY_ARMED
             * 15   = DELAYED_EXTERNALLY_ARMED
             * 16   = EVENT
             * 17   = ERROR
             */

            $this->SendDebug(__FUNCTION__, 'Optisches Signal: ' . $opticalSignal, 0);
            /*
             * 0    = DISABLE_OPTICAL_SIGNAL
             * 1    = BLINKING_ALTERNATELY_REPEATING
             * 2    = BLINKING_BOTH_REPEATING
             * 3    = DOUBLE_FLASHING_REPEATING
             * 4    = FLASHING_BOTH_REPEATING
             * 5    = CONFIRMATION_SIGNAL_0 LONG_LONG
             * 6    = CONFIRMATION_SIGNAL_1 LONG_SHORT
             * 7    = CONFIRMATION_SIGNAL_2 LONG_SHORT_SHORT
             */

            $this->SendDebug(__FUNCTION__, 'Einheit Zeitdauer: ' . $durationUnit, 0);
            /*
             * 0    = SECONDS
             * 1    = MINUTES
             * 2    = HOURS
             */

            $this->SendDebug(__FUNCTION__, 'Wert Zeitdauer: ' . $durationValue, 0);
            /*
             * n    = Value
             */

            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird geschaltet.', 0);
            $parameter1 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
            $this->SendDebug(__FUNCTION__, 'Ergebnis akustisches Signal: ' . json_encode($parameter1), 0);
            $parameter2 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
            $this->SendDebug(__FUNCTION__, 'Ergebnis optisches Signal: ' . json_encode($parameter2), 0);
            $parameter3 = @HM_WriteValueInteger($id, 'DURATION_UNIT', $durationUnit);
            $this->SendDebug(__FUNCTION__, 'Ergebnis Einheit Zeitdauer: ' . json_encode($parameter3), 0);
            $parameter4 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $durationValue);
            $this->SendDebug(__FUNCTION__, 'Ergebnis Wert Zeitdauer: ' . json_encode($parameter4), 0);

            // Switch alarm siren again in case of error
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                $this->SendDebug(__FUNCTION__, 'Es wird erneut versucht die Alarmsirene zu schalten.', 0);
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $parameter1 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
                $parameter2 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
                $parameter3 = @HM_WriteValueInteger($id, 'DURATION_UNIT', $durationUnit);
                $parameter4 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $durationValue);
            }

            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                $result = false;
            }

            if (!$result) {
                $this->SendDebug(__FUNCTION__, 'Fehler, die Alarmsirene konnte nicht geschaltet werden!', 0);
            } else {
                $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wurde erfolgreich geschaltet.', 0);
            }
        }

        // Virtual remote control
        $virtualRemoteControl = false;
        switch ($AlarmType) {
            case 1: # Pre alarm
                $id = $this->ReadPropertyInteger('VirtualRemoteControlPreAlarm');
                if ($id != 0 && @IPS_ObjectExists($id)) {
                    $virtualRemoteControl = true;
                    $result = @RequestAction($id, true);
                }
                break;

            case 2: # Main alarm
                $id = $this->ReadPropertyInteger('VirtualRemoteControlMainAlarm');
                if ($id != 0 && @IPS_ObjectExists($id)) {
                    $virtualRemoteControl = true;
                    $result = @RequestAction($id, true);
                }
                break;

            case 3: # Post alarm
                $id = $this->ReadPropertyInteger('VirtualRemoteControlPostAlarm');
                if ($id != 0 && @IPS_ObjectExists($id)) {
                    $virtualRemoteControl = true;
                    $result = @RequestAction($id, true);
                }
                break;

            default: # Alarm siren off
                $id = $this->ReadPropertyInteger('VirtualRemoteControlAlarmSirenOff');
                if ($id != 0 && @IPS_ObjectExists($id)) {
                    $virtualRemoteControl = true;
                    $result = @RequestAction($id, true);
                }
        }

        if ($virtualRemoteControl) {
            if (!$result) {
                $this->SendDebug(__FUNCTION__, 'Fehler, die virtuelle Fernbedienung konnte nicht geschaltet werden!', 0);
            } else {
                $this->SendDebug(__FUNCTION__, 'Die virtuelle Fernbedienung wurde erfolgreich geschaltet.', 0);
            }
        }

        return $result;
    }

    private function CheckSignallingAmount(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        $maximum = $this->ReadPropertyInteger('MainAlarmMaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $text = 'Abbruch, die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                return false;
            }
        }
        return true;
    }
}