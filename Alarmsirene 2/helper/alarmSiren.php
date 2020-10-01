<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */
/** @noinspection PhpUndefinedFunctionInspection */

declare(strict_types=1);

trait AS2_alarmSiren
{
    #################### HmIP-ASIR, HmIP-ASIR-O, HmIP-ASIR-2

    /**
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

    /**
     * Toggles the alarm siren off an on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ToggleAlarmSiren(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $actualAlarmSirenState = $this->GetValue('AlarmSiren');
        $actualSignallingAmount = $this->GetValue('SignallingAmount');
        // Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet.', 0);
            $this->WriteAttributeBoolean('MainAlarm', false);
            $this->UpdateParameter();
            $this->DisableTimers();
            $this->SetValue('AlarmSiren', false);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            $result = true;
            //Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.ToggleAlarmSiren', 5000)) {
                return false;
            }
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
            $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
            $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
                $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                    $result = false;
                    //Revert
                    $this->SetValue('AlarmSiren', $actualAlarmSirenState);
                    $this->SetValue('SignallingAmount', $actualSignallingAmount);
                    $message = 'Fehler, die Alarmsirene konnte nicht ausgeschaltet werden!';
                    $this->SendDebug(__FUNCTION__, $message, 0);
                    $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
                }
            }
            if ($result) {
                //Protocol
                $text = 'Die Alarmsirene wurde ausgeschaltet. (ID ' . $id . ')';
                $this->UpdateProtocol($text);
            }
            //Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.ToggleAlarmSiren');
        }
        // Activate
        if ($State) {
            //Check mute mode
            if ($this->CheckMuteMode()) {
                return false;
            }
            //Check signaling amount
            if (!$this->CheckSignallingAmount()) {
                return false;
            }
            //Check if main alarm is already turned on
            if ($this->CheckMainAlarm()) {
                return false;
            }
            $this->SetValue('AlarmSiren', true);
            //Delay
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay > 0) {
                $this->SetTimerInterval('ActivateMainAlarm', $delay * 1000);
                $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird in ' . $delay . ' Sekunden eingeschaltet.', 0);
                if (!$actualAlarmSirenState) {
                    // Protocol
                    $text = 'Die Alarmsirene wird in ' . $delay . ' Sekunden eingeschaltet. (ID ' . $id . ')';
                    $this->UpdateProtocol($text);
                }
                //Check pre alarm
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    $result = $this->TriggerPreAlarm();
                }
            }
            // No delay, activate alarm siren immediately
            else {
                $result = $this->ActivateMainAlarm();
                if (!$result) {
                    //Revert
                    $this->SetValue('AlarmSiren', $actualAlarmSirenState);
                    $this->SetValue('SignallingAmount', $actualSignallingAmount);
                }
            }
        }
        return $result;
    }

    /**
     * Checks the trigger variable.
     *
     * @param int $SenderID
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function CheckTrigger(int $SenderID): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        //Check mute mode
        if ($this->CheckMuteMode()) {
            return false;
        }
        $result = true;
        //Trigger variables
        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($triggerVariables)) {
            foreach ($triggerVariables as $variable) {
                $id = $variable->ID;
                if ($SenderID == $id) {
                    $use = $variable->ID;
                    if ($use) {
                        $actualValue = intval(GetValue($id));
                        $triggerValue = $variable->TriggerValue;
                        if ($actualValue == $triggerValue) {
                            $result = $this->ToggleAlarmSiren(true);
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Triggers the pre alarm.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function TriggerPreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        //Check mute mode
        if ($this->CheckMuteMode()) {
            return false;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $result = true;
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.PreAlarm', 5000)) {
            return false;
        }
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
        $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
        $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 10);
        $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 3);
        if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
            $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 10);
            $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 3);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                $result = false;
                $message = 'Fehler, der Voralarm konnte nicht ausgegeben werden!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
            }
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.PreAlarm');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Voralarm wurde erfolgreich ausgegeben.', 0);
        }
        return $result;
    }

    /**
     * Triggers the main alarm.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ActivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        //Check mute mode
        if ($this->CheckMuteMode()) {
            return false;
        }
        //Check signaling amount
        if (!$this->CheckSignallingAmount()) {
            return false;
        }
        // Check if the main alarm is already turned on
        if ($this->CheckMainAlarm()) {
            return false;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $result = true;
        $this->WriteAttributeBoolean('MainAlarm', true);
        $this->UpdateParameter();
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetValue('AlarmSiren', true);
        $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
        $acousticSignal = $this->GetValue('AcousticSignal');
        $opticalSignal = $this->GetValue('OpticalSignal');
        $duration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.MainAlarm', 5000)) {
            return false;
        }
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
        $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
        $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
        $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
        if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
            $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $acousticSignal);
            $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                $result = false;
                $message = 'Fehler, der Hauptalarm konnte nicht ausgegeben werden!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
            }
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.MainAlarm');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Hauptalarm wurde erfolgreich ausgegeben.', 0);
            //Protocol
            $text = 'Die Alarmsirene wurde eingeschaltet. (ID ' . $id . ')';
            $this->UpdateProtocol($text);
        }
        $this->SetTimerInterval('DeactivateAcousticSignal', $duration * 1000);
        return $result;
    }

    /**
     * Deactivates the acoustic signal.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function DeactivateAcousticSignal(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $this->SetTimerInterval('DeactivateAcousticSignal', 0);
        $acousticDuration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        $opticalDuration = $this->ReadPropertyInteger('MainAlarmOpticalSignallingDuration') * 60;
        $result = true;
        if ($opticalDuration == $acousticDuration) {
            $result = $this->ToggleAlarmSiren(false);
        }
        if ($opticalDuration > $acousticDuration) {
            $opticalSignal = $this->GetValue('OpticalSignal');
            $duration = ($opticalDuration - $acousticDuration);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            //Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.DeactivateAcousticSignal', 5000)) {
                return false;
            }
            $id = $this->ReadPropertyInteger('AlarmSiren');
            $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
            $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
            $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
            $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
            if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
                $parameter3 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                $parameter4 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                    $result = false;
                    $message = 'Fehler, das akustische Signal konnte nicht deaktiviert werden!';
                    $this->SendDebug(__FUNCTION__, $message, 0);
                    $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
                }
            }
            //Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.DeactivateAcousticSignal');
            if ($result) {
                $this->SendDebug(__FUNCTION__, 'Das akustische Signal wurde erfolgreich deaktiviert.', 0);
                $this->SendDebug(__FUNCTION__, 'Das optische Signal wurde erfolgreich aktiviert.', 0);
            }
            $this->SetTimerInterval('DeactivateMainAlarm', $duration * 1000);
        }
        return $result;
    }

    /**
     * Deactivates the optical Signalling
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function DeactivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        return $this->ToggleAlarmSiren(false);
    }

    /**
     * Resets the signalling amount.
     */
    public function ResetSignallingAmount(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SetValue('SignallingAmount', 0);
    }

    #################### Private

    /**
     * Checks for an existing alarm siren.
     *
     * @return bool
     * false    = no alarm siren
     * true     = ok
     */
    private function CheckAlarmSiren(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $result = false;
            $message = 'Abbruch, es ist keine Alarmsirene ausgewählt!';
            $this->SendDebug(__FUNCTION__, $message, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
        }
        return $result;
    }

    /**
     * Checks if the main alarm is already turned on.
     *
     * @return bool
     * false    = off
     * true     = already turned on
     */
    private function CheckMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $state = $this->ReadAttributeBoolean('MainAlarm');
        if ($state) {
            $message = 'Abbruch, die akustische Signalisierung ist bereits eingeschaltet!';
            $this->SendDebug(__FUNCTION__, $message, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
        }
        return $state;
    }

    /**
     * Checks the signalling amount.
     *
     * @return bool
     * false    = maximum signalling reached
     * true     = ok
     */
    private function CheckSignallingAmount(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $execute = true;
        $maximum = $this->ReadPropertyInteger('MainAlarmMaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $execute = false;
                $message = 'Abbruch, die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
            }
        }
        return $execute;
    }

    /**
     * Updates the protocol.
     *
     * @param string $Message
     */
    private function UpdateProtocol(string $Message): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $protocolID = $this->ReadPropertyInteger('AlarmProtocol');
        if ($protocolID != 0 && @IPS_ObjectExists($protocolID)) {
            $timestamp = date('d.m.Y, H:i:s');
            $logText = $timestamp . ', ' . $Message;
            @APRO_UpdateMessages($protocolID, $logText, 0);
        }
    }

    /**
     * Sets the timer for resetting the signalling amount.
     */
    private function SetResetSignallingAmountTimer()
    {
        $timestamp = strtotime('next day midnight');
        $now = time();
        $interval = ($timestamp - $now) * 1000;
        $this->SetTimerInterval('ResetSignallingAmount', $interval);
    }
}