<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/HmIP-ASIR
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class AlarmsireneHmIPASIR extends IPSModule
{
    // Helper
    use AS_HMIPASIR_alarmProtocol;
    use AS_HMIPASIR_alarmSiren;
    use AS_HMIPASIR_backupRestore;
    use AS_HMIPASIR_muteMode;
    use AS_HMIPASIR_triggerVariable;

    // Constants
    private const DELAY_MILLISECONDS = 250;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        // Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('EnableAlarmSiren', true);
        $this->RegisterPropertyBoolean('EnableStatus', true);
        $this->RegisterPropertyBoolean('EnableSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableResetSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableMuteMode', true);
        // Alarm siren
        $this->RegisterPropertyInteger('AlarmSiren', 0);
        $this->RegisterPropertyInteger('AlarmSirenSwitchingDelay', 0);
        // Pre alarm
        $this->RegisterPropertyBoolean('UsePreAlarm', true);
        $this->RegisterPropertyInteger('PreAlarmDuration', 3);
        $this->RegisterPropertyInteger('PreAlarmAcousticSignal', 10);
        $this->RegisterPropertyInteger('PreAlarmOpticalSignal', 3);
        // Main alarm
        $this->RegisterPropertyBoolean('UseMainAlarm', true);
        $this->RegisterPropertyInteger('MainAlarmSignallingDelay', 30);
        $this->RegisterPropertyInteger('MainAlarmDuration', 180);
        $this->RegisterPropertyInteger('MainAlarmMaximumSignallingAmount', 3);
        $this->RegisterPropertyInteger('MainAlarmAcousticSignal', 3);
        $this->RegisterPropertyInteger('MainAlarmOpticalSignal', 1);
        // Post alarm
        $this->RegisterPropertyBoolean('UsePostAlarm', true);
        $this->RegisterPropertyInteger('PostAlarmDuration', 5);
        $this->RegisterPropertyInteger('PostAlarmOpticalSignal', 1);
        // Virtual remote controls
        $this->RegisterPropertyInteger('VirtualRemoteControlAlarmSirenOff', 0);
        $this->RegisterPropertyInteger('VirtualRemoteControlPreAlarm', 0);
        $this->RegisterPropertyInteger('VirtualRemoteControlMainAlarm', 0);
        $this->RegisterPropertyInteger('VirtualRemoteControlPostAlarm', 0);
        $this->RegisterPropertyInteger('VirtualRemoteControlSwitchingDelay', 0);
        // Trigger Variables
        $this->RegisterPropertyString('TriggerVariables', '[]');
        // Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
        // Mute mode
        $this->RegisterPropertyBoolean('UseAutomaticMuteMode', false);
        $this->RegisterPropertyString('MuteModeStartTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('MuteModeEndTime', '{"hour":6,"minute":0,"second":0}');

        // Variables
        // Alarm siren
        $id = @$this->GetIDForIdent('AlarmSiren');
        $this->RegisterVariableBoolean('AlarmSiren', 'Alarmsirene', '~Switch', 10);
        $this->EnableAction('AlarmSiren');
        if ($id == false) {
            IPS_SetIcon(@$this->GetIDForIdent('AlarmSiren'), 'Alert');
        }
        // Status
        $profile = 'AS_HMIPASIR.' . $this->InstanceID . '.Status';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
            IPS_SetVariableProfileIcon($profile, 'Speaker');
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Voralarm', '', 0xFFFF00);
        IPS_SetVariableProfileAssociation($profile, 2, 'Hauptalarm', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 3, 'Nachalarm', '', 0xFFFF00);
        $this->RegisterVariableInteger('Status', 'Status', $profile, 20);
        // Signalling amount
        $id = @$this->GetIDForIdent('SignallingAmount');
        $this->RegisterVariableInteger('SignallingAmount', 'Auslösungen', '', 30);
        if ($id == false) {
            IPS_SetIcon(@$this->GetIDForIdent('SignallingAmount'), 'Warning');
        }
        // Reset signalling amount
        $profile = 'AS_HMIPASIR.' . $this->InstanceID . '.ResetSignallingAmount';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Reset', 'Repeat', 0xFF0000);
        $this->RegisterVariableInteger('ResetSignallingAmount', 'Rückstellung', $profile, 40);
        $this->EnableAction('ResetSignallingAmount');
        // Mute mode
        $profile = 'AS_HMIPASIR.' . $this->InstanceID . '.MuteMode.Reversed';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Speaker', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Speaker', 0x00FF00);
        $this->RegisterVariableBoolean('MuteMode', 'Stummschaltung', $profile, 50);
        $this->EnableAction('MuteMode');

        // Timers
        $this->RegisterTimer('ExecuteMainAlarm', 0, 'AS_HMIPASIR_ExecuteMainAlarm(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('ExecutePostAlarm', 0, 'AS_HMIPASIR_ExecutePostAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAlarmSiren', 0, 'AS_HMIPASIR_ToggleAlarmSiren(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('ResetSignallingAmount', 0, 'AS_HMIPASIR_ResetSignallingAmount(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartMuteMode', 0, 'AS_HMIPASIR_StartMuteMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopMuteMode', 0, 'AS_HMIPASIR_StopMuteMode(' . $this->InstanceID . ',);');
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Options
        IPS_SetHidden($this->GetIDForIdent('AlarmSiren'), !$this->ReadPropertyBoolean('EnableAlarmSiren'));
        IPS_SetHidden($this->GetIDForIdent('Status'), !$this->ReadPropertyBoolean('EnableStatus'));
        IPS_SetHidden($this->GetIDForIdent('SignallingAmount'), !$this->ReadPropertyBoolean('EnableSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('ResetSignallingAmount'), !$this->ReadPropertyBoolean('EnableResetSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('MuteMode'), !$this->ReadPropertyBoolean('EnableMuteMode'));

        // Reset
        $this->SetTimerInterval('ExecuteMainAlarm', 0);
        $this->SetTimerInterval('ExecutePostAlarm', 0);
        $this->SetTimerInterval('DeactivateAlarmSiren', 0);
        $this->SetTimerInterval('ResetSignallingAmount', (strtotime('next day midnight') - time()) * 1000);
        $this->SetValue('SignallingAmount', 0);
        if ($this->ToggleAlarmSiren(false)) {
            $this->SetValue('Status', 0);
        }

        // Validation
        if (!$this->ValidateConfiguration()) {
            return;
        }

        $this->RegisterMessages();
        $this->SetMuteModeTimer();
        $this->CheckMuteModeTimer();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profiles
        $profiles = ['Status', 'ResetSignallingAmount', 'MuteMode.Reversed'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'AS_HMIPASIR.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
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

                // $Data[0] = actual value
                // $Data[1] = value changed
                // $Data[2] = last value
                // $Data[3] = timestamp actual value
                // $Data[4] = timestamp value changed
                // $Data[5] = timestamp last value

                // Check trigger
                $valueChanged = 'false';
                if ($Data[1]) {
                    $valueChanged = 'true';
                }
                $scriptText = 'AS_HMIPASIR_CheckTriggerVariable(' . $this->InstanceID . ', ' . $SenderID . ', ' . $valueChanged . ');';
                @IPS_RunScriptText($scriptText);
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Trigger variables
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                $rowColor = '#C0FFC0'; # light green
                $use = $variable->Use;
                if (!$use) {
                    $rowColor = '';
                }
                $id = $variable->ID;
                if ($id == 0 || @!IPS_ObjectExists($id)) {
                    $rowColor = '#FFC0C0'; # red
                }
                $formData['elements'][4]['items'][0]['values'][] = [
                    'Use'              => $use,
                    'ID'               => $id,
                    'TriggerType'      => $variable->TriggerType,
                    'TriggerValue'     => $variable->TriggerValue,
                    'TriggerAction'    => $variable->TriggerAction,
                    'rowColor'         => $rowColor];
            }
        }

        // Registered messages
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $senderName = 'Objekt #' . $senderID . ' existiert nicht';
            $rowColor = '#FFC0C0'; # red
            if (@IPS_ObjectExists($senderID)) {
                $senderName = IPS_GetName($senderID);
                $rowColor = '#C0FFC0'; # light green
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
                'SenderID'              => $senderID,
                'SenderName'            => $senderName,
                'MessageID'             => $messageID,
                'MessageDescription'    => $messageDescription,
                'rowColor'              => $rowColor];
        }

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

    private function ValidateConfiguration(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        $result = true;
        $status = 102;

        // Check pre and main alarm
        if ($this->ReadPropertyBoolean('UsePreAlarm') && $this->ReadPropertyBoolean('UseMainAlarm')) {
            if ($this->ReadPropertyInteger('MainAlarmSignallingDelay') <= $this->ReadPropertyInteger('PreAlarmDuration')) {
                $result = false;
                $status = 200;
                $text = 'Abbruch, die Einschaltverzögerung des Hauptalarms ist zu gering!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
            }
        }

        // Maintenance mode
        $maintenance = $this->CheckMaintenanceMode();
        if ($maintenance) {
            $result = false;
            $status = 104;
        }

        IPS_SetDisabled($this->InstanceID, $maintenance);
        $this->SetStatus($status);

        return $result;
    }

    private function CheckMaintenanceMode(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);

        $result = $this->ReadPropertyBoolean('MaintenanceMode');
        if ($result) {
            $text = 'Abbruch, der Wartungsmodus ist aktiv!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
        }

        return $result;
    }

    private function RegisterMessages(): void
    {
        // Unregister VM_UPDATE
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

        // Register VM_UPDATE
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
}