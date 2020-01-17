<?php

// Declare
declare(strict_types=1);

trait ASIR_alarmSiren
{
    //#################### HM-Sec-Sir-WM

    /**
     *
     * CHANNEL  = 3, SWITCH_PANIC
     *
     * STATE:
     *
     * false    = TURN_OFF
     * true     = TURN_ON
     *
     * CHANNEL  = 4, ARMING
     *
     * ARMSTATE:
     *
     * 0        = DISARMED
     * 1        = EXTSENS_ARMED
     * 2        = ALLSENS_ARMED
     * 3        = ALARM_BLOCKED
     */

    //#################### HmIP-ASIR-O
    //#################### HmIP-ASIR
    //#################### HmIP-ASIR-2

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
     * Toggles the alarm siren.
     *
     * @param bool $State
     * false    = turn alarm siren off
     * true     = turn alarm siren on
     */
    public function ToggleAlarmSiren(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen.', 0);
        // Check alarm sirens
        if (!$this->CheckExecution()) {
            return;
        }
        $count = $this->GetAlarmSirenAmount();
        $lastState = $this->GetValue('AlarmSiren');
        // Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirenen werden ausgeschaltet.', 0);
            $this->WriteAttributeBoolean('AcousticSignallingActive', false);
            $this->SendDebug(__FUNCTION__, 'Attribute AcousticSignallingActive: false', 0);
            $this->DisableTimers();
            $this->SetValue('AlarmSiren', false);
            $this->SetValue('AlarmSirenState', 0);
            $this->SetValue('SignallingAmount', 0);
            // Alarm sirens
            if ($count > 0) {
                $i = 0;
                $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
                foreach ($alarmSirens as $alarmSiren) {
                    if ($alarmSiren->Use) {
                        $id = $alarmSiren->ID;
                        if ($id != 0 && @IPS_ObjectExists($id)) {
                            $i++;
                            $type = $alarmSiren->Type;
                            $deactivate = true;
                            switch ($type) {
                                // Variable
                                case 1:
                                    $deactivate = @RequestAction($id, false);
                                    break;

                                // Script
                                case 2:
                                    $deactivate = @IPS_RunScriptEx($id, ['State' => 0]);
                                    break;

                                // HM-Sec-Sir-WM
                                case 3:
                                    $deactivate = @HM_WriteValueBoolean($id, 'STATE', false);
                                    break;

                                // HmIP-ASIR-O, HmIP-ASIR, HmIP-ASIR-2
                                case 4:
                                case 5:
                                case 6:
                                    $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                                    $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
                                    $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
                                    $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                                    if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                                        $deactivate = false;
                                    }
                                    break;

                            }
                            // Log & Debug
                            if (!$deactivate) {
                                $text = 'Die Alarmsirene konnte nicht ausgeschaltet werden. (ID ' . $id . ')';
                                $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                            } else {
                                $text = 'Die Alarmsirene wurde ausgeschaltet. (ID ' . $id . ')';
                            }
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            // Protocol
                            if ($lastState) {
                                $this->UpdateProtocol($text);
                            }
                            // Execution delay for next alarm siren
                            if ($count > 1 && $i < $count) {
                                IPS_Sleep(self::DELAY_MILLISECONDS);
                            }
                        }
                    }
                }
            }
        }
        // Activate
        if ($State) {
            // Check signaling amount
            if (!$this->CheckSignallingAmount()) {
                return;
            }
            // Check if alarm siren is already running
            if ($this->CheckRunningAlarmSiren()) {
                return;
            }
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirenen werden eingeschaltet.', 0);
            $this->SetValue('AlarmSiren', true);
            // Delay
            $delay = $this->ReadPropertyInteger('SignallingDelay');
            if ($delay > 0) {
                $this->SetValue('AlarmSirenState', 2);
                $this->SetTimerInterval('ActivateAcousticSignalling', $delay * 1000);
                // Alarm sirens
                if ($count > 0) {
                    $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
                    foreach ($alarmSirens as $alarmSiren) {
                        if ($alarmSiren->Use) {
                            $id = $alarmSiren->ID;
                            if ($id != 0 && @IPS_ObjectExists($id)) {
                                $delayed = true;
                                $type = $alarmSiren->Type;
                                if ($type == 2) {
                                    $delayed = @IPS_RunScriptEx($id, ['State' => 2]);
                                }
                                // Log & Debug
                                if (!$delayed) {
                                    $text = 'Die Alarmsirene konnte nicht verzögert eingeschaltet werden. (ID ' . $id . ')';
                                    $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                                } else {
                                    $text = 'Die Alarmsirene wird verzögert in ' . $delay . ' Sekunden eingeschaltet. (ID ' . $id . ')';
                                }
                                $this->SendDebug(__FUNCTION__, $text, 0);
                                // Protocol
                                if (!$lastState) {
                                    $this->UpdateProtocol($text);
                                }
                            }
                        }
                    }
                }
                // Check pre alarm
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    $this->TriggerPreAlarm();
                }
            }
            // No delay, activate alarm siren immediately
            else {
                $this->SetValue('AlarmSirenState', 1);
                $this->ActivateAcousticSignalling();
            }
        }
    }

    /**
     * Triggers a pre alarm.
     */
    public function TriggerPreAlarm(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        // Check alarm sirens
        if (!$this->CheckExecution()) {
            return;
        }
        // Alarm sirens
        $count = $this->GetAlarmSirenAmount();
        if ($count > 0) {
            $this->SendDebug(__FUNCTION__, 'Der Voralarm wird ausgelöst.', 0);
            $i = 0;
            $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
            foreach ($alarmSirens as $alarmSiren) {
                if ($alarmSiren->Use) {
                    $id = $alarmSiren->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $i++;
                        $type = $alarmSiren->Type;
                        switch ($type) {
                            // HmIP-ASIR-O, HmIP-ASIR, HmIP-ASIR-2
                            case 4:
                            case 5:
                            case 6:
                                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
                                $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $this->ReadPropertyInteger('OpticalSignalPreAlarm'));
                                $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $this->ReadPropertyInteger('AcousticSignalPreAlarm'));
                                // Log & Debug
                                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                                    $text = 'Der Voralarm konnte nicht ausgelöst werden. (ID ' . $id . ')';
                                    $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                                } else {
                                    $text = 'Der Voralarm wurde ausgelöst. (ID ' . $id . ')';
                                }
                                $this->SendDebug(__FUNCTION__, $text, 0);
                                break;

                        }
                        // Execution delay for next alarm siren
                        if ($count > 1 && $i < $count) {
                            IPS_Sleep(self::DELAY_MILLISECONDS);
                        }
                    }
                }
            }
        }
    }

    /**
     * Activates the acoustic signalling.
     */
    public function ActivateAcousticSignalling(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        // Check alarm sirens
        if (!$this->CheckExecution()) {
            return;
        }
        // Check signaling amount
        if (!$this->CheckSignallingAmount()) {
            return;
        }
        // Check if alarm siren is already running
        if ($this->CheckRunningAlarmSiren()) {
            return;
        }
        $this->WriteAttributeBoolean('AcousticSignallingActive', true);
        $this->SendDebug(__FUNCTION__, 'Attribute AcousticSignallingActive: true', 0);
        $this->SetTimerInterval('ActivateAcousticSignalling', 0);
        $this->SetValue('AlarmSiren', true);
        $this->SetValue('AlarmSirenState', 1);
        $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
        $duration = $this->ReadPropertyInteger('AcousticSignallingDuration');
        $this->SendDebug(__FUNCTION__, 'Die akustische Signalisierung der Alarmsirenen wird eingeschaltet.', 0);
        // Alarm sirens
        $count = $this->GetAlarmSirenAmount();
        if ($count > 0) {
            $i = 0;
            $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
            foreach ($alarmSirens as $alarmSiren) {
                if ($alarmSiren->Use) {
                    $id = $alarmSiren->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $i++;
                        $type = $alarmSiren->Type;
                        $activate = true;
                        switch ($type) {
                            // Variable
                            case 1:
                                $activate = @RequestAction($id, true);
                                break;

                            // Script
                            case 2:
                                $activate = IPS_RunScriptEx($id, ['State' => 1]);
                                break;

                            // HM-Sec-Sir-WM
                            case 3:
                                $activate = @HM_WriteValueBoolean($id, 'STATE', true);
                                break;

                            // HmIP-ASIR-O, HmIP-ASIR, HmIP-ASIR-2
                            case 4:
                            case 5:
                            case 6:
                                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
                                $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $this->ReadPropertyInteger('OpticalSignalMainAlarm'));
                                $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', $this->ReadPropertyInteger('AcousticSignalMainAlarm'));
                                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                                    $activate = false;
                                }
                                break;

                        }
                        // Log & Debug
                        if (!$activate) {
                            $text = 'Die Alarmsirene konnte nicht eingeschaltet werden. (ID ' . $id . ')';
                            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                        } else {
                            $text = 'Die Alarmsirene wurde eingeschaltet. (ID ' . $id . ')';
                        }
                        $this->SendDebug(__FUNCTION__, $text, 0);
                        // Protocol
                        $this->UpdateProtocol($text);
                        // Execution delay for next alarm siren
                        if ($count > 1 && $i < $count) {
                            IPS_Sleep(self::DELAY_MILLISECONDS);
                        }
                    }
                }
            }
        }
        $this->SetTimerInterval('DeactivateAcousticSignalling', $duration * 1000);
    }

    /**
     * Deactivates the acoustic signalling.
     */
    public function DeactivateAcousticSignalling(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        // Check alarm sirens
        if (!$this->CheckExecution()) {
            return;
        }
        $this->WriteAttributeBoolean('AcousticSignallingActive', false);
        $this->SendDebug(__FUNCTION__, 'Attribute AcousticSignallingActive: false', 0);
        $this->SetTimerInterval('DeactivateAcousticSignalling', 0);
        $this->SetValue('AlarmSiren', false);
        $this->SetValue('AlarmSirenState', 0);
        $homematicIP = false;
        $this->SendDebug(__FUNCTION__, 'Die akustische Signalisierung der Alarmsirenen wird ausgeschaltet.', 0);
        // Alarm sirens
        $count = $this->GetAlarmSirenAmount();
        if ($count > 0) {
            $i = 0;
            $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
            $acousticDuration = $this->ReadPropertyInteger('AcousticSignallingDuration');
            $opticalDuration = $this->ReadPropertyInteger('OpticalSignallingDuration') * 60;
            if ($opticalDuration > $acousticDuration) {
                $useOpticalSignalling = true;
                $opticalSignal = $this->ReadPropertyInteger('OpticalSignalMainAlarm');
                $duration = ($opticalDuration - $acousticDuration);
            } else {
                $useOpticalSignalling = false;
                $opticalSignal = 0;
                $duration = 3;
            }
            foreach ($alarmSirens as $alarmSiren) {
                if ($alarmSiren->Use) {
                    $id = $alarmSiren->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $i++;
                        $type = $alarmSiren->Type;
                        $deactivate = true;
                        switch ($type) {
                            // Variable
                            case 1:
                                $deactivate = @RequestAction($id, false);
                                $useOpticalSignalling = false;
                                break;

                            // Script
                            case 2:
                                $deactivate = IPS_RunScriptEx($id, ['State' => 0]);
                                break;

                            // HM-Sec-Sir-WM
                            case 3:
                                $deactivate = @HM_WriteValueBoolean($id, 'STATE', false);
                                $useOpticalSignalling = false;
                                break;

                            // HmIP-ASIR-O, HmIP-ASIR, HmIP-ASIR-2
                            case 4:
                            case 5:
                            case 6:
                                $homematicIP = true;
                                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', $duration);
                                $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', $opticalSignal);
                                $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                                    $deactivate = false;
                                }
                                break;

                        }
                        // Log & Debug
                        if (!$deactivate) {
                            $text = 'Die Alarmsirene konnte nicht ausgeschaltet werden. (ID ' . $id . ')';
                            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                        } else {
                            $text = 'Die Alarmsirene wurde ausgeschaltet. (ID ' . $id . ')';
                        }
                        $this->SendDebug(__FUNCTION__, $text, 0);
                        // Protocol
                        $this->UpdateProtocol($text);
                        // Log & Debug again
                        if ($useOpticalSignalling && $deactivate) {
                            $text = 'Die optische Signalisierung der Alarmsirene wurde eingeschaltet. (ID ' . $id . ')';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                        }
                        // Execution delay for next alarm siren
                        if ($count > 1 && $i < $count) {
                            IPS_Sleep(self::DELAY_MILLISECONDS);
                        }
                    }
                }
            }
            // We have an Homematic IP alarm siren
            if ($homematicIP) {
                $this->SetTimerInterval('DeactivateOpticalSignalling', $duration * 1000);
            }
        }
    }

    /**
     * Deactivates the optical Signalling (HmIP only)
     */
    public function DeactivateOpticalSignalling(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        if (!$this->CheckExecution()) {
            return;
        }
        $this->SetTimerInterval('DeactivateOpticalSignalling', 0);
        // Alarm sirens
        $count = $this->GetAlarmSirenAmount();
        if ($count > 0) {
            $this->SendDebug(__FUNCTION__, 'Die optische Signalisierung der Alarmsirenen wird ausgeschaltet.', 0);
            $i = 0;
            $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
            foreach ($alarmSirens as $alarmSiren) {
                if ($alarmSiren->Use) {
                    $id = $alarmSiren->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $i++;
                        $type = $alarmSiren->Type;
                        switch ($type) {
                            // HmIP-ASIR-O, HmIP-ASIR, HmIP-ASIR-2
                            case 4:
                            case 5:
                            case 6:
                                $parameter1 = @HM_WriteValueInteger($id, 'DURATION_UNIT', 0);
                                $parameter2 = @HM_WriteValueInteger($id, 'DURATION_VALUE', 3);
                                $parameter3 = @HM_WriteValueInteger($id, 'OPTICAL_ALARM_SELECTION', 0);
                                $parameter4 = @HM_WriteValueInteger($id, 'ACOUSTIC_ALARM_SELECTION', 0);
                                // Log & Debug
                                if (!$parameter1 || !$parameter2 || !$parameter3 || !$parameter4) {
                                    $text = 'Die optische Signalisierung der Alarmsirene konnte nicht ausgeschaltet werden. (ID ' . $id . ')';
                                    $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                                } else {
                                    $text = 'Die optische Signailisierung der Alarmsirene wurde ausgeschaltet. (ID ' . $id . ')';
                                }
                                $this->SendDebug(__FUNCTION__, $text, 0);
                                break;

                        }
                        // Execution delay for next alarm siren
                        if ($count > 1 && $i < $count) {
                            IPS_Sleep(self::DELAY_MILLISECONDS);
                        }
                    }
                }
            }
        }
    }

    //#################### Private

    /**
     * Checks the execution.
     *
     * @return bool
     * false    = no alarm siren exists
     * true     = at least one alarm siren exists
     */
    private function CheckExecution(): bool
    {
        $execute = false;
        $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
        if (!empty($alarmSirens)) {
            foreach ($alarmSirens as $alarmSiren) {
                if ($alarmSiren->Use) {
                    $id = $alarmSiren->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $execute = true;
                    }
                }
            }
        }
        // Log & Debug
        if (!$execute) {
            $text = 'Es ist keine Alarmsirene vorhanden!';
            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
            $this->SendDebug(__FUNCTION__, $text, 0);
        }
        return $execute;
    }

    /**
     * Gets the amount of alarm sirens.
     *
     * @return int
     * Returns the amount of used alarm sirens.
     */
    private function GetAlarmSirenAmount(): int
    {
        $amount = 0;
        $alarmSirens = json_decode($this->ReadPropertyString('AlarmSirens'));
        if (!empty($alarmSirens)) {
            foreach ($alarmSirens as $device) {
                if ($device->Use) {
                    $id = $device->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $amount++;
                    }
                }
            }
        }
        return $amount;
    }

    /**
     * Checks for a running alarm siren.
     *
     * @return bool
     * false    = no acoustic signalling
     * true     = already in use
     */
    private function CheckRunningAlarmSiren(): bool
    {
        $running = false;
        if ($this->ReadAttributeBoolean('AcousticSignallingActive')) {
            $text = 'Abbruch, Die akustische Signalisierung ist bereits eingeschaltet!';
            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
            $this->SendDebug(__FUNCTION__, $text, 0);
            $running = true;
        }
        return $running;
    }

    /**
     * Checks the signaling amount.
     *
     * @return bool
     * false    = maximum signalling reached
     * true     = ok
     */
    private function CheckSignallingAmount(): bool
    {
        $execute = true;
        $maximum = $this->ReadPropertyInteger('MaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $execute = false;
                $text = 'Abbruch, Die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                $this->SendDebug(__FUNCTION__, $text, 0);
            }
        }
        return $execute;
    }

    /**
     * Resets the signalling amount to zero.
     *
     * @param bool $State
     * false    = don't reset
     * true     = reset to zero
     */
    private function ResetSignallingAmount(bool $State): void
    {
        $this->SetValue('ResetSignallingAmount', $State);
        if ($State) {
            $this->SetValue('SignallingAmount', 0);
            $this->SetValue('ResetSignallingAmount', false);
        }
    }

    /**
     * Updates the protocol.
     *
     * @param string $Message
     */
    private function UpdateProtocol(string $Message): void
    {
        $protocolID = $this->ReadPropertyInteger('AlarmProtocol');
        if ($protocolID != 0 && @IPS_ObjectExists($protocolID)) {
            $timestamp = date('d.m.Y, H:i:s');
            $logText = $timestamp . ', ' . $Message;
            @APRO_UpdateMessages($protocolID, $logText, 0);
        }
    }
}