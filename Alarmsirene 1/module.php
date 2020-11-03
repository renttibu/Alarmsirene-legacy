<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmsirene 1 (Variable)
 *
 * @prefix      AS1
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Alarmsirene
 *
 * @guids       Library
 *              {6984F242-48A3-5594-B0D1-061D71C6B0E5}
 *
 *              Alarmsirene 1
 *             	{856F7F9E-CA7F-823D-340F-A041937831E3}
 */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Alarmsirene1 extends IPSModule
{
    //Helper
    use AS1_alarmSiren;
    use AS1_backupRestore;
    use AS1_muteMode;

    //Constants
    private const DELAY_MILLISECONDS = 250;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->RegisterAttributes();
        $this->RegisterTimers();
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
        $this->DeleteProfiles();
    }

    public function ApplyChanges()
    {
        //Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        //Never delete this line!
        parent::ApplyChanges();
        //Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->SetOptions();
        $this->ResetAttributes();
        $this->DisableTimers();
        $this->SetResetSignallingAmountTimer();
        $this->ResetSignallingAmount();
        $this->ValidateConfiguration();
        $this->RegisterMessages();
        $this->SetMuteModeTimer();
        $this->CheckMuteModeTimer();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, $TimeStamp . ', SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:
                //$Data[0] = actual value
                //$Data[1] = value changed
                //$Data[2] = last value
                //$Data[3] = timestamp actual value
                //$Data[4] = timestamp value changed
                //$Data[5] = timestamp last value
                if ($this->CheckMaintenanceMode()) {
                    return;
                }
                //Trigger action
                if ($Data[1]) {
                    $scriptText = 'AS1_CheckTrigger(' . $this->InstanceID . ', ' . $SenderID . ');';
                    IPS_RunScriptText($scriptText);
                }
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        //Trigger variables
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                $rowColor = '#C0FFC0'; //light green
                $use = $variable->Use;
                if (!$use) {
                    $rowColor = '';
                }
                $id = $variable->ID;
                if ($id == 0 || @!IPS_ObjectExists($id)) {
                    $rowColor = '#FFC0C0'; //light red
                }
                $formData['elements'][4]['items'][1]['values'][] = [
                    'Use'                                              => $use,
                    'ID'                                               => $id,
                    'TriggerValueOn'                                   => $variable->TriggerValueOn,
                    'TriggerValueOff'                                  => $variable->TriggerValueOff,
                    'rowColor'                                         => $rowColor];
            }
        }
        //Registered messages
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $senderName = 'Objekt #' . $senderID . ' existiert nicht';
            $rowColor = '#FFC0C0'; //light red
            if (@IPS_ObjectExists($senderID)) {
                $senderName = IPS_GetName($senderID);
                $rowColor = '#C0FFC0'; //light green
            }
            switch ($messageID) {
                case [10001]:
                    $messageDescription = 'IPS_KERNELSTARTED';
                    break;

                case [10603]:
                    $messageDescription = 'VM_UPDATE';
                    break;

                default:
                    $messageDescription = 'keine Bezeichnung';
            }
            $formData['actions'][1]['items'][0]['values'][] = [
                'SenderID'                                              => $senderID,
                'SenderName'                                            => $senderName,
                'MessageID'                                             => $messageID,
                'MessageDescription'                                    => $messageDescription,
                'rowColor'                                              => $rowColor];
        }
        //Attribute
        $state = 'Aus';
        $mainAlarm = $this->ReadAttributeBoolean('MainAlarm');
        if ($mainAlarm) {
            $state = 'An';
        }
        $formData['actions'][2]['items'][0]['caption'] = 'Hauptalarm: ' . $state;
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    #################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AlarmSiren':
                $this->ToggleAlarmSiren($Value);
                break;

            case 'ResetSignallingAmount':
                $this->ResetSignallingAmount();
                break;

            case 'MuteMode':
                $this->ToggleMuteMode($Value);
                break;

        }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function RegisterProperties(): void
    {
        //Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('EnableAlarmSiren', true);
        $this->RegisterPropertyBoolean('EnableSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableResetSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableMuteMode', true);
        //Alarm siren
        $this->RegisterPropertyInteger('AlarmSiren', 0);
        $this->RegisterPropertyInteger('AlarmSirenSwitchingDelay', 0);
        //Pre alarm
        $this->RegisterPropertyBoolean('UsePreAlarm', true);
        $this->RegisterPropertyInteger('PreAlarmDuration', 3);
        //Main alarm
        $this->RegisterPropertyBoolean('UseMainAlarm', true);
        $this->RegisterPropertyInteger('MainAlarmSignallingDelay', 30);
        $this->RegisterPropertyInteger('MainAlarmAcousticSignallingDuration', 180);
        $this->RegisterPropertyInteger('MainAlarmMaximumSignallingAmount', 3);
        //Trigger
        $this->RegisterPropertyString('TriggerVariables', '[]');
        // Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
        //Mute mode
        $this->RegisterPropertyBoolean('UseAutomaticMuteMode', false);
        $this->RegisterPropertyString('MuteModeStartTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('MuteModeEndTime', '{"hour":6,"minute":0,"second":0}');
    }

    private function CreateProfiles(): void
    {
        //Reset signalling amount
        $profile = 'AS1.' . $this->InstanceID . '.ResetSignallingAmount';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Reset', 'Repeat', 0xFF0000);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['ResetSignallingAmount'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'AS1.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
    }

    private function RegisterVariables(): void
    {
        //Alarm siren
        $this->RegisterVariableBoolean('AlarmSiren', 'Alarmsirene', '~Switch', 10);
        $this->EnableAction('AlarmSiren');
        $id = @$this->GetIDForIdent('AlarmSiren');
        IPS_SetIcon($id, 'Alert');
        //Signalling amount
        $this->RegisterVariableInteger('SignallingAmount', 'Auslösungen', '', 40);
        $id = @$this->GetIDForIdent('SignallingAmount');
        IPS_SetIcon($id, 'Warning');
        //Reset signalling amount
        $profile = 'AS1.' . $this->InstanceID . '.ResetSignallingAmount';
        $this->RegisterVariableInteger('ResetSignallingAmount', 'Rückstellung', $profile, 50);
        $this->EnableAction('ResetSignallingAmount');
        //Mute mode
        $this->RegisterVariableBoolean('MuteMode', 'Stummschaltung', '~Switch', 60);
        $this->EnableAction('MuteMode');
    }

    private function SetOptions(): void
    {
        IPS_SetHidden($this->GetIDForIdent('AlarmSiren'), !$this->ReadPropertyBoolean('EnableAlarmSiren'));
        IPS_SetHidden($this->GetIDForIdent('SignallingAmount'), !$this->ReadPropertyBoolean('EnableSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('ResetSignallingAmount'), !$this->ReadPropertyBoolean('EnableResetSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('MuteMode'), !$this->ReadPropertyBoolean('EnableMuteMode'));
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ActivateMainAlarm', 0, 'AS1_ActivateMainAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivatePreAlarm', 0, 'AS1_DeactivatePreAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateMainAlarm', 0, 'AS1_DeactivateMainAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('ResetSignallingAmount', 0, 'AS1_ResetSignallingAmount(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartMuteMode', 0, 'AS1_StartMuteMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopMuteMode', 0, 'AS1_StopMuteMode(' . $this->InstanceID . ',);');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeBoolean('MainAlarm', false);
    }

    private function ResetAttributes(): void
    {
        $this->WriteAttributeBoolean('MainAlarm', false);
    }

    private function ValidateConfiguration(): void
    {
        $status = 102;
        //Pre alarm
        $usePreAlarm = $this->ReadPropertyBoolean('UsePreAlarm');
        if ($usePreAlarm) {
            //Check pre alarm duration and main alarm signalling delay
            $preAlarmDuration = $this->ReadPropertyInteger('PreAlarmDuration');
            $mainAlarmSignallingDelay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($mainAlarmSignallingDelay <= ($preAlarmDuration + 2)) {
                $status = 200;
                $message = 'Abbruch, die Verzögerung für den Hauptalarm ist zu gering!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
            }
        }
        //Maintenance mode
        $maintenance = $this->CheckMaintenanceMode();
        if ($maintenance) {
            $status = 104;
        }
        IPS_SetDisabled($this->InstanceID, $maintenance);
        $this->SetStatus($status);
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = $this->ReadPropertyBoolean('MaintenanceMode');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wartungsmodus ist aktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, der Wartungsmodus ist aktiv!', KL_WARNING);
        }
        return $result;
    }

    private function UpdateParameter(): void
    {
        $state = 'Aus';
        $mainAlarm = $this->ReadAttributeBoolean('MainAlarm');
        if ($mainAlarm) {
            $state = 'An';
        }
        $caption = 'Hauptalarm: ' . $state;
        $this->UpdateFormField('AttributeMainAlarm', 'caption', $caption);
    }

    private function RegisterMessages(): void
    {
        //Unregister
        $messages = $this->GetMessageList();
        if (!empty($messages)) {
            foreach ($messages as $id => $message) {
                foreach ($message as $messageType) {
                    if ($messageType == VM_UPDATE) {
                        $this->UnregisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }
        //Register
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                if ($variable->Use) {
                    if ($variable->ID != 0 && @IPS_ObjectExists($variable->ID)) {
                        $this->RegisterMessage($variable->ID, VM_UPDATE);
                    }
                }
            }
        }
    }

    private function CheckMuteMode(): bool
    {
        $muteMode = boolval($this->GetValue('MuteMode'));
        if ($muteMode) {
            $message = 'Abbruch, die Stummschaltung ist aktiv!';
            $this->SendDebug(__FUNCTION__, $message, 0);
        }
        return $muteMode;
    }
}