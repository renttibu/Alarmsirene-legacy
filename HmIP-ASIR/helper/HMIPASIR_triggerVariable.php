<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/HmIP-ASIR
 */

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait HMIPASIR_triggerVariable
{
    public function CheckTriggerVariable(int $SenderID, bool $ValueChanged): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SendDebug(__FUNCTION__, 'ID ' . $SenderID . ', Wert hat sich geändert: ' . json_encode($ValueChanged), 0);

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
                $triggerValue = $triggerVariable->TriggerValue;
                switch ($triggerVariable->TriggerType) {
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
                $action = $triggerVariable->TriggerAction;
                switch ($action) {
                    case 0:
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Aktion: Alarmsirene ausschalten.', 0);
                        $result = $this->ToggleAlarmSiren(false);
                        break;

                    case 1:
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Aktion: Alarmsirene einschalten.', 0);

                        if ($this->CheckMaintenanceMode()) {
                            return false;
                        }

                        if ($this->CheckMuteMode()) {
                            return false;
                        }

                        $result = $this->ToggleAlarmSiren(true);
                        break;

                    case 2:
                        $this->SendDebug(__FUNCTION__, 'ID ' . $id . ', Aktion: Panikalarm.', 0);

                        $result = $this->ExecuteMainAlarm(true);
                        break;

                    default:
                        $this->SendDebug(__FUNCTION__, 'Es konnte keine Aktion ermittelt werden!', 0);
                }
            }
        }

        return $result;
    }
}