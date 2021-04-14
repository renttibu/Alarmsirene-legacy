<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/Alarmsirene%202
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait AS2_alarmSiren
{
    #################### HmIP-ASIR, HmIP-ASIR-O, HmIP-ASIR-2

    /*
     *
     * CHANNEL  = 3, ALARM_SWITCH_VIRTUAL_RECEIVER
     *
     * ACOUSTIC_ALARM_SELECTION:
     *
     * 0        = DISABLE_ACOUSTIC_SIGNAL
     * 1        = FREQUENCY_RISING
     * 2        = FREQUENCY_FALLING
     * 3        = FREQUENCY_RISING_AND_FALLING
     * 4        = FREQUENCY_ALTERNATING_LOW_HIGH
     * 5        = FREQUENCY_ALTERNATING_LOW_MID_HIGH
     * 6        = FREQUENCY_HIGHON_OFF
     * 7        = FREQUENCY_HIGHON_LONGOFF
     * 8        = FREQUENCY_LOWON_OFF_HIGHON_OFF
     * 9        = FREQUENCY_LOWON_LONGOFF_HIGHON_LONGOFF
     * 10       = LOW_BATTERY
     * 11       = DISARMED
     * 12       = INTERNALLY_ARMED
     * 13       = EXTERNALLY_ARMED
     * 14       = DELAYED_INTERNALLY_ARMED
     * 15       = DELAYED_EXTERNALLY_ARMED
     * 16       = EVENT
     * 17       = ERROR
     *
     * OPTICAL_ALARM_SELECTION:
     *
     * 0        = DISABLE_OPTICAL_SIGNAL
     * 1        = BLINKING_ALTERNATELY_REPEATING
     * 2        = BLINKING_BOTH_REPEATING
     * 3        = DOUBLE_FLASHING_REPEATING
     * 4        = FLASHING_BOTH_REPEATING
     * 5        = CONFIRMATION_SIGNAL_0 LONG_LONG
     * 6        = CONFIRMATION_SIGNAL_1 LONG_SHORT
     * 7        = CONFIRMATION_SIGNAL_2 LONG_SHORT_SHORT
     *
     * DURATION_UNIT:
     *
     * 0        = SECONDS
     * 1        = MINUTES
     * 2        = HOURS
     *
     * DURATION_VALUE:
     *
     * n        = VALUE
     *
     */

    public function ToggleAlarmSiren(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' ausgeführt.', 0);
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $actualAlarmSirenState = $this->GetValue('AlarmSiren');
        $actualSignallingAmount = $this->GetValue('SignallingAmount');
        // Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet', 0);
            $this->SetTimerInterval('ActivateMainAlarm', 0);
            $this->SetTimerInterval('DeactivateAcousticSignal', 0);
            $this->SetTimerInterval('DeactivateMainAlarm', 0);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            // Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.ToggleAlarmSiren', 5000)) {
                return false;
            }
            $this->SetValue('AlarmSiren', false);
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $this->SendDebug(__FUNCTION__, 'DURATION_UNIT: 0, RESULT: ' . json_encode($parameter1), 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
            $this->SendDebug(__FUNCTION__, 'DURATION_VALUE: 3, RESULT: ' . json_encode($parameter2), 0);
            $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
            $this->SendDebug(__FUNCTION__, 'OPTICAL_ALARM_SELECTION: 0, RESULT: ' . json_encode($parameter3), 0);
            $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
            $this->SendDebug(__FUNCTION__, 'ACOUSTIC_ALARM_SELECTION: 0, RESULT: ' . json_encode($parameter4), 0);
            $result = true;
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
                $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
                $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                    IPS_Sleep(self::DELAY_MILLISECONDS * 2);
                    $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                    $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
                    $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
                    $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                    if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                        $result = false;
                    }
                }
            }
            // Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.ToggleAlarmSiren');
            if ($result) {
                $this->WriteAttributeBoolean('MainAlarm', false);
                $this->UpdateParameter();
                $text = 'Die Alarmsirene wurde ausgeschaltet';
                $this->SendDebug(__FUNCTION__, $text, 0);
                if ($State != $actualAlarmSirenState) {
                    $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
                }
            } else {
                // Revert on failure
                $this->SetValue('AlarmSiren', $actualAlarmSirenState);
                $this->SetValue('SignallingAmount', $actualSignallingAmount);
                $text = 'Fehler, die Alarmsirene konnte nicht ausgeschaltet werden!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
                if ($State != $actualAlarmSirenState) {
                    $this->UpdateAlarmProtocol($text . ' (ID ' . $id . ')');
                }
            }
        }
        // Activate
        if ($State) {
            if ($this->CheckMaintenanceMode()) {
                return $result;
            }
            if ($this->CheckMuteMode()) {
                return $result;
            }
            if (!$this->CheckSignallingAmount()) {
                return $result;
            }
            // Check if main alarm is already turned on
            if ($this->CheckMainAlarm()) {
                return $result;
            }
            // Delay
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay > 0) {
                $this->SetTimerInterval('ActivateMainAlarm', $delay * 1000);
                $unit = 'Sekunden';
                if ($delay == 1) {
                    $unit = 'Sekunde';
                }
                $this->SetValue('AlarmSiren', true);
                $text = 'Die Alarmsirene wird in ' . $delay . ' ' . $unit . ' eingeschaltet';
                $this->SendDebug(__FUNCTION__, $text, 0);
                if (!$actualAlarmSirenState) {
                    if ($State != $actualAlarmSirenState) {
                        $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
                    }
                }
                // Check pre alarm (delay needed for main alarm)
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    $result = $this->TriggerPreAlarm();
                }
            }
            // No delay, activate alarm siren immediately
            else {
                $result = $this->ActivateMainAlarm();
                if ($State != $actualAlarmSirenState) {
                    $result = $this->ActivateMainAlarm();
                }
            }
        }
        return $result;
    }

    public function TriggerPreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
            $this->SendDebug(__FUNCTION__, 'Der Voralarm ist nicht aktiviert!', 0);
            return false;
        }
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        $result = true;
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        // Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerPreAlarm', 5000)) {
            return false;
        }
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $duration = $this->ReadPropertyInteger('PreAlarmDuration');
        $acousticSignal = $this->ReadPropertyInteger('PreAlarmAcousticSignal');
        $opticalSignal = $this->ReadPropertyInteger('PreAlarmOpticalSignal');
        $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
        $this->SendDebug(__FUNCTION__, 'DURATION_UNIT: 0, RESULT: ' . json_encode($parameter1), 0);
        $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
        $this->SendDebug(__FUNCTION__, 'DURATION_VALUE: ' . $duration . ', RESULT: ' . json_encode($parameter2), 0);
        $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
        $this->SendDebug(__FUNCTION__, 'OPTICAL_ALARM_SELECTION: ' . $opticalSignal . ', RESULT: ' . json_encode($parameter3), 0);
        $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
        $this->SendDebug(__FUNCTION__, 'ACOUSTIC_ALARM_SELECTION: ' . $acousticSignal . ', RESULT: ' . json_encode($parameter4), 0);
        if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
            $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
            $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                $result = false;
            }
        }
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.TriggerPreAlarm');
        if ($result) {
            $text = 'Der Voralarm wurde eingeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
        } else {
            $text = 'Fehler, der Voralarm konnte nicht eingeschaltet werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        }
        $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        return $result;
    }

    public function ActivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        if (!$this->ReadPropertyBoolean('UseMainAlarm')) {
            $this->SendDebug(__FUNCTION__, 'Der Hauptalarm ist nicht aktiviert!', 0);
            return false;
        }
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        if (!$this->CheckSignallingAmount()) {
            return false;
        }
        // Check if the main alarm is already turned on
        if ($this->CheckMainAlarm()) {
            return false;
        }
        return $this->TriggerMainAlarm();
    }

    public function TriggerMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetTimerInterval('DeactivateAcousticSignal', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $this->SetValue('AlarmSiren', true);
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        // Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerMainAlarm', 5000)) {
            return false;
        }
        $result = true;
        $duration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        $acousticSignal = $this->GetValue('AcousticSignal');
        $opticalSignal = $this->GetValue('OpticalSignal');
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
        $this->SendDebug(__FUNCTION__, 'DURATION_UNIT: 0, RESULT: ' . json_encode($parameter1), 0);
        $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
        $this->SendDebug(__FUNCTION__, 'DURATION_VALUE: ' . $duration . ', RESULT: ' . json_encode($parameter2), 0);
        $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
        $this->SendDebug(__FUNCTION__, 'OPTICAL_ALARM_SELECTION: ' . $opticalSignal . ', RESULT: ' . json_encode($parameter3), 0);
        $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
        $this->SendDebug(__FUNCTION__, 'ACOUSTIC_ALARM_SELECTION: ' . $acousticSignal . ', RESULT: ' . json_encode($parameter4), 0);
        if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
            $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
            $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                $result = false;
            }
        }
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.TriggerMainAlarm');
        if ($result) {
            $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
            $this->WriteAttributeBoolean('MainAlarm', true);
            $this->UpdateParameter();
            $text = 'Die Alarmsirene wurde eingeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
            $unit = 'Sekunden';
            if ($duration == 1) {
                $unit = 'Sekunde';
            }
            $text = 'Die Alarmsirene wird in ' . $duration . ' ' . $unit . ' ausgeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
        } else {
            // Revert on failure
            $this->SetValue('AlarmSiren', false);
            $text = 'Fehler, die Alarmsirene konnte nicht eingeschaltet werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        }
        $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        $this->SetTimerInterval('DeactivateAcousticSignal', $duration * 1000);
        return $result;
    }

    public function DeactivateAcousticSignal(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetTimerInterval('DeactivateAcousticSignal', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $result = true;
        $acousticDuration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        $opticalDuration = $this->ReadPropertyInteger('MainAlarmOpticalSignallingDuration') * 60;
        if ($opticalDuration == $acousticDuration) {
            return $this->ToggleAlarmSiren(false);
        }
        if ($opticalDuration > $acousticDuration) {
            $opticalSignal = $this->GetValue('OpticalSignal');
            $duration = ($opticalDuration - $acousticDuration);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            // Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.DeactivateAcousticSignal', 5000)) {
                return false;
            }
            $id = $this->ReadPropertyInteger('AlarmSiren');
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $this->SendDebug(__FUNCTION__, 'DURATION_UNIT: 0, RESULT: ' . json_encode($parameter1), 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
            $this->SendDebug(__FUNCTION__, 'DURATION_VALUE: ' . $duration . ', RESULT: ' . json_encode($parameter2), 0);
            $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
            $this->SendDebug(__FUNCTION__, 'OPTICAL_ALARM_SELECTION: ' . $opticalSignal . ', RESULT: ' . json_encode($parameter3), 0);
            $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
            $this->SendDebug(__FUNCTION__, 'ACOUSTIC_ALARM_SELECTION: 0, RESULT: ' . json_encode($parameter4), 0);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
                $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
                $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                    IPS_Sleep(self::DELAY_MILLISECONDS);
                    $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                    $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
                    $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
                    $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                    if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                        $result = false;
                    }
                }
            }
            // Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.DeactivateAcousticSignal');
            if ($result) {
                $this->SendDebug(__FUNCTION__, 'Das akustische Signal wurde erfolgreich deaktiviert.', 0);
                $this->SendDebug(__FUNCTION__, 'Das optische Signal wurde erfolgreich aktiviert.', 0);
            } else {
                $text = 'Fehler, das akustische Signal konnte nicht deaktiviert werden!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
                $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
            }
            $this->SetTimerInterval('DeactivateMainAlarm', $duration * 1000);
        }
        return $result;
    }

    public function DeactivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        return $this->ToggleAlarmSiren(false);
    }

    public function CheckTriggerVariable(int $SenderID, bool $ValueChanged): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SendDebug(__FUNCTION__, 'ID ' . $SenderID . ', Wert hat sich geändert: ' . json_encode($ValueChanged), 0);

        if (!$this->CheckAlarmSiren()) {
            return false;
        }

        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (empty($triggerVariables)) {
            return false;
        }

        $result = false;

        foreach ($triggerVariables as $triggerVariable) {
            $execute = false;
            $id = $triggerVariable->ID;
            if ($id == $SenderID && $triggerVariable->Use) {
                $type = IPS_GetVariable($id)['VariableType'];
                $triggerValue = $triggerVariable->Value;
                switch ($triggerVariable->Trigger) {
                    case 0: # on change (bool, integer, float, string)
                        if ($ValueChanged) {
                            $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Änderung (bool, integer, float, string)', 0);
                            $execute = true;
                        }
                        break;

                    case 1: # on update (bool, integer, float, string)
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Aktualisierung (bool, integer, float, string)', 0);
                        $execute = true;
                        break;

                    case 2: # on limit drop, once (integer, float)
                        switch ($type) {
                            case 1: # integer
                                if ($ValueChanged) {
                                    if ($triggerValue == 'false') {
                                        $triggerValue = '0';
                                    }
                                    if ($triggerValue == 'true') {
                                        $triggerValue = '1';
                                    }
                                    if (GetValueInteger($SenderID) < intval($triggerValue)) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, einmalig (integer)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                            case 2: # float
                                if ($ValueChanged) {
                                    if (GetValueFloat($SenderID) < floatval(str_replace(',', '.', $triggerValue))) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, einmalig (float)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                        }
                        break;

                    case 3: # on limit drop, every time (integer, float)
                        switch ($type) {
                            case 1: # integer
                                if ($triggerValue == 'false') {
                                    $triggerValue = '0';
                                }
                                if ($triggerValue == 'true') {
                                    $triggerValue = '1';
                                }
                                if (GetValueInteger($SenderID) < intval($triggerValue)) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, mehrmalig (integer)', 0);
                                    $execute = true;
                                }
                                break;

                            case 2: # float
                                if (GetValueFloat($SenderID) < floatval(str_replace(',', '.', $triggerValue))) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, mehrmalig (float)', 0);
                                    $execute = true;
                                }
                                break;

                        }
                        break;

                    case 4: # on limit exceed, once (integer, float)
                        switch ($type) {
                            case 1: # integer
                                if ($ValueChanged) {
                                    if ($triggerValue == 'false') {
                                        $triggerValue = '0';
                                    }
                                    if ($triggerValue == 'true') {
                                        $triggerValue = '1';
                                    }
                                    if (GetValueInteger($SenderID) > intval($triggerValue)) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, einmalig (integer)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                            case 2: # float
                                if ($ValueChanged) {
                                    if (GetValueFloat($SenderID) > floatval(str_replace(',', '.', $triggerValue))) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, einmalig (float)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                        }
                        break;

                    case 5: # on limit exceed, every time (integer, float)
                        switch ($type) {
                            case 1: # integer
                                if ($triggerValue == 'false') {
                                    $triggerValue = '0';
                                }
                                if ($triggerValue == 'true') {
                                    $triggerValue = '1';
                                }
                                if (GetValueInteger($SenderID) > intval($triggerValue)) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, mehrmalig (integer)', 0);
                                    $execute = true;
                                }
                                break;

                            case 2: # float
                                if (GetValueFloat($SenderID) > floatval(str_replace(',', '.', $triggerValue))) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei Grenzunterschreitung, mehrmalig (float)', 0);
                                    $execute = true;
                                }
                                break;

                        }
                        break;

                    case 6: # on specific value, once (bool, integer, float, string)
                        switch ($type) {
                            case 0: # bool
                                if ($ValueChanged) {
                                    if ($triggerValue == 'false') {
                                        $triggerValue = '0';
                                    }
                                    if (GetValueBoolean($SenderID) == boolval($triggerValue)) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, einmalig (bool)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                            case 1: # integer
                                if ($ValueChanged) {
                                    if ($triggerValue == 'false') {
                                        $triggerValue = '0';
                                    }
                                    if ($triggerValue == 'true') {
                                        $triggerValue = '1';
                                    }
                                    if (GetValueInteger($SenderID) == intval($triggerValue)) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, einmalig (integer)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                            case 2: # float
                                if ($ValueChanged) {
                                    if (GetValueFloat($SenderID) == floatval(str_replace(',', '.', $triggerValue))) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, einmalig (float)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                            case 3: # string
                                if ($ValueChanged) {
                                    if (GetValueString($SenderID) == (string) $triggerValue) {
                                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, einmalig (string)', 0);
                                        $execute = true;
                                    }
                                }
                                break;

                        }
                        break;

                    case 7: # on specific value, every time (bool, integer, float, string)
                        switch ($type) {
                            case 0: # bool
                                if ($triggerValue == 'false') {
                                    $triggerValue = '0';
                                }
                                if (GetValueBoolean($SenderID) == boolval($triggerValue)) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, mehrmalig (bool)', 0);
                                    $execute = true;
                                }
                                break;

                            case 1: # integer
                                if ($triggerValue == 'false') {
                                    $triggerValue = '0';
                                }
                                if ($triggerValue == 'true') {
                                    $triggerValue = '1';
                                }
                                if (GetValueInteger($SenderID) == intval($triggerValue)) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, mehrmalig (integer)', 0);
                                    $execute = true;
                                }
                                break;

                            case 2: # float
                                $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, mehrmalig (float)', 0);
                                if (GetValueFloat($SenderID) == floatval(str_replace(',', '.', $triggerValue))) {
                                    $execute = true;
                                }
                                break;

                            case 3: # string
                                if (GetValueString($SenderID) == (string) $triggerValue) {
                                    $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Bei bestimmten Wert, mehrmalig (string)', 0);
                                    $execute = true;
                                }
                                break;

                        }
                        break;

                }
            }
            if ($execute) {
                $action = $triggerVariable->Action;
                switch ($action) {
                    case 0:
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Aktion: Alarmsirene ausschalten', 0);
                        $result = $this->ToggleAlarmSiren(false);
                        break;

                    case 1:
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Aktion: Alarmsirene einschalten', 0);
                        if ($this->CheckMaintenanceMode()) {
                            return false;
                        }
                        if ($this->CheckMuteMode()) {
                            return false;
                        }
                        $this->SetValue('AcousticSignal', $this->ReadPropertyInteger('MainAlarmAcousticSignal'));
                        $this->SetValue('OpticalSignal', $this->ReadPropertyInteger('MainAlarmOpticalSignal'));
                        $result = $this->ToggleAlarmSiren(true);
                        break;

                    case 2:
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Aktion: Panikalarm', 0);
                        if ($this->CheckMaintenanceMode()) {
                            return false;
                        }
                        $this->SetValue('AcousticSignal', $this->ReadPropertyInteger('MainAlarmAcousticSignal'));
                        $this->SetValue('OpticalSignal', $this->ReadPropertyInteger('MainAlarmOpticalSignal'));
                        $result = $this->TriggerMainAlarm();
                        break;

                    default:
                        $this->SendDebug(__FUNCTION__, 'Es konnte keine Aktion ermittelt werden!', 0);
                }
            }
        }

        return $result;
    }

    public function ResetSignallingAmount(): void
    {
        $this->SetValue('SignallingAmount', 0);
    }

    #################### Private

    private function CheckAlarmSiren(): bool
    {
        $id = $this->ReadPropertyInteger('AlarmSiren');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $text = 'Abbruch, es ist keine Alarmsirene ausgewählt!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
            return false;
        }
        return true;
    }

    private function CheckMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $state = $this->ReadAttributeBoolean('MainAlarm');
        if ($state) {
            $text = 'Abbruch, die akustische Signalisierung ist bereits eingeschaltet!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
        }
        return $state;
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