<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmsirene 2 (HmIP-ASIR, HmIP-ASIR-O, HmIP-ASIR-2)
 *
 * @prefix      AS2
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
 *              Alarmsirene 2
 *             	{3836A9EA-7DE0-508E-79CF-17D04C593D45}
 */

declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Alarmsirene2 extends IPSModule
{
    // Helper
    use AS2_alarmSiren;
    use AS2_backupRestore;
    use AS2_muteMode;

    // Constants
    private const DELAY_MILLISECONDS = 250;
    private const ALARMSIRENE_LIBRARY_GUID = '{6984F242-48A3-5594-B0D1-061D71C6B0E5}';
    private const ALARMSIRENE2_MODULE_GUID = '{3836A9EA-7DE0-508E-79CF-17D04C593D45}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->RegisterAttributes();
        $this->RegisterTimers();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
        $this->DeleteProfiles();
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
        $this->SetOptions();
        $this->ResetAttributes();
        $this->DisableTimers();
        $this->SetResetSignallingAmountTimer();
        $this->ResetSignallingAmount();
        $this->ValidateConfiguration();
        $this->CheckMaintenanceMode();
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
                    $scriptText = 'AS2_CheckTrigger(' . $this->InstanceID . ', ' . $SenderID . ');';
                    IPS_RunScriptText($scriptText);
                }
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        //Info
        $moduleInfo = [];
        $library = IPS_GetLibrary(self::ALARMSIRENE_LIBRARY_GUID);
        $module = IPS_GetModule(self::ALARMSIRENE2_MODULE_GUID);
        $moduleInfo['name'] = $module['ModuleName'];
        $moduleInfo['version'] = $library['Version'] . '-' . $library['Build'];
        $moduleInfo['date'] = date('d.m.Y', $library['Date']);
        $moduleInfo['time'] = date('H:i', $library['Date']);
        $moduleInfo['developer'] = $library['Author'];
        $formData['elements'][0]['items'][1]['caption'] = "ID:\t\t\t\t" . $this->InstanceID;
        $formData['elements'][0]['items'][2]['caption'] = "Modul:\t\t\t" . $moduleInfo['name'];
        $formData['elements'][0]['items'][3]['caption'] = "Version:\t\t\t" . $moduleInfo['version'];
        $formData['elements'][0]['items'][4]['caption'] = "Datum:\t\t\t" . $moduleInfo['date'];
        $formData['elements'][0]['items'][5]['caption'] = "Uhrzeit:\t\t\t" . $moduleInfo['time'];
        $formData['elements'][0]['items'][6]['caption'] = "Entwickler:\t\t" . $moduleInfo['developer'] . ', Normen Thiel';
        $formData['elements'][0]['items'][7]['caption'] = "Präfix:\t\t\tAS2";
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
                $formData['elements'][5]['items'][1]['values'][] = [
                    'Use'                                           => $use,
                    'ID'                                            => $id,
                    'TriggerValue'                                  => $variable->TriggerValue,
                    'rowColor'                                      => $rowColor];
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

            case 'AcousticSignal':
            case 'OpticalSignal':
                $this->SetValue($Ident, $Value);
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

    private function KernelReady()
    {
        $this->ApplyChanges();
    }

    private function RegisterProperties(): void
    {
        //Info
        $this->RegisterPropertyString('Note', '');
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        //Functions
        $this->RegisterPropertyBoolean('EnableAlarmSiren', true);
        $this->RegisterPropertyBoolean('EnableAcousticSignal', true);
        $this->RegisterPropertyBoolean('EnableOpticalSignal', true);
        $this->RegisterPropertyBoolean('EnableSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableResetSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableMuteMode', true);
        //Alarm siren
        $this->RegisterPropertyInteger('AlarmSiren', 0);
        $this->RegisterPropertyInteger('AlarmSirenSwitchingDelay', 0);
        //Pre alarm
        $this->RegisterPropertyBoolean('UsePreAlarm', true);
        //Main alarm
        $this->RegisterPropertyBoolean('UseMainAlarm', true);
        $this->RegisterPropertyInteger('MainAlarmSignallingDelay', 30);
        $this->RegisterPropertyInteger('MainAlarmAcousticSignallingDuration', 180);
        $this->RegisterPropertyInteger('MainAlarmOpticalSignallingDuration', 5);
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
        //Acoustic signal
        $profile = 'AS2.' . $this->InstanceID . '.AcousticSignal';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
            IPS_SetVariableProfileIcon($profile, 'Speaker');
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Kein akustisches Signal', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 1, 'Frequenz steigend', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 2, 'Frequenz fallend', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 3, 'Frequenz steigend/fallend', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 4, 'Frequenz tief/hoch', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 5, 'Frequenz tief/mittel/hoch', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 6, 'Frequenz hoch ein/aus', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 7, 'Frequenz hoch ein, lang aus', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 8, 'Frequenz tief ein/aus, hoch ein/aus', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 9, 'Frequenz tief ein - lang aus, hoch ein - lang aus', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 10, 'Batterie leer', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 11, 'Unscharf', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 12, 'Intern Scharf', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 13, 'Extern Scharf', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 14, 'Intern verzögert Scharf', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 15, 'Extern verzögert Scharf', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 16, 'Ereignis', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 17, 'Fehler', '', 0x00FF00);
        //Optical signal
        $profile = 'AS2.' . $this->InstanceID . '.OpticalSignal';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
            IPS_SetVariableProfileIcon($profile, 'Bulb');
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Kein optisches Signal', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 1, 'Abwechselndes langsames Blinken', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 2, 'Gleichzeitiges langsames Blinken', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 3, 'Gleichzeitiges schnelles Blinken', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 4, 'Gleichzeitiges kurzes Blinken', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 5, 'Bestätigungssignal 0 - lang lang', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 6, 'Bestätigungssignal 1 - lang kurz', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 7, 'Bestätigungssignal 2 - lang kurz kurz', '', 0x00FF00);
        //Reset signalling amount
        $profile = 'AS2.' . $this->InstanceID . '.ResetSignallingAmount';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Reset', 'Repeat', 0xFF0000);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['AcousticSignal', 'OpticalSignal', 'ResetSignallingAmount'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'AS2.' . $this->InstanceID . '.' . $profile;
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
        $id = $this->GetIDForIdent('AlarmSiren');
        IPS_SetIcon($id, 'Alert');
        //Acoustic Signal
        $profile = 'AS2.' . $this->InstanceID . '.AcousticSignal';
        $this->RegisterVariableInteger('AcousticSignal', 'Akustisches Signal', $profile, 20);
        $this->EnableAction('AcousticSignal');
        //Optical Signal
        $profile = 'AS2.' . $this->InstanceID . '.OpticalSignal';
        $this->RegisterVariableInteger('OpticalSignal', 'Optisches Signal', $profile, 30);
        $this->EnableAction('OpticalSignal');
        //Signalling amount
        $this->RegisterVariableInteger('SignallingAmount', 'Auslösungen', '', 40);
        $id = $this->GetIDForIdent('SignallingAmount');
        IPS_SetIcon($id, 'Warning');
        //Reset signalling amount
        $profile = 'AS2.' . $this->InstanceID . '.ResetSignallingAmount';
        $this->RegisterVariableInteger('ResetSignallingAmount', 'Rückstellung', $profile, 50);
        $this->EnableAction('ResetSignallingAmount');
        //Mute mode
        $this->RegisterVariableBoolean('MuteMode', 'Stummschaltung', '~Switch', 60);
        $this->EnableAction('MuteMode');
    }

    private function SetOptions(): void
    {
        IPS_SetHidden($this->GetIDForIdent('AlarmSiren'), !$this->ReadPropertyBoolean('EnableAlarmSiren'));
        IPS_SetHidden($this->GetIDForIdent('AcousticSignal'), !$this->ReadPropertyBoolean('EnableAcousticSignal'));
        IPS_SetHidden($this->GetIDForIdent('OpticalSignal'), !$this->ReadPropertyBoolean('EnableOpticalSignal'));
        IPS_SetHidden($this->GetIDForIdent('SignallingAmount'), !$this->ReadPropertyBoolean('EnableSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('ResetSignallingAmount'), !$this->ReadPropertyBoolean('EnableResetSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('MuteMode'), !$this->ReadPropertyBoolean('EnableMuteMode'));
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ActivateMainAlarm', 0, 'AS2_ActivateMainAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAcousticSignal', 0, 'AS2_DeactivateAcousticSignal(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateMainAlarm', 0, 'AS2_DeactivateMainAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('ResetSignallingAmount', 0, 'AS2_ResetSignallingAmount(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartMuteMode', 0, 'AS2_StartMuteMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopMuteMode', 0, 'AS2_StopMuteMode(' . $this->InstanceID . ',);');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetTimerInterval('DeactivateAcousticSignal', 0);
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
        // Check acoustical and optical duration
        $acousticDuration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        $opticalDuration = $this->ReadPropertyInteger('MainAlarmOpticalSignallingDuration') * 60;
        if ($opticalDuration < $acousticDuration) {
            $status = 200;
            $message = 'Abbruch, die Dauer der optischen Signalisierung ist zu gering!';
            $this->SendDebug(__FUNCTION__, $message, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
        }
        $this->SetStatus($status);
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

    private function CheckMaintenanceMode(): bool
    {
        $result = $this->ReadPropertyBoolean('MaintenanceMode');
        $status = 102;
        if ($result) {
            $status = 104;
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wartungsmodus ist aktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, der Wartungsmodus ist aktiv!', KL_WARNING);
        }
        $this->SetStatus($status);
        IPS_SetDisabled($this->InstanceID, $result);
        return $result;
    }

    private function CheckMuteMode(): bool
    {
        $muteMode = boolval($this->GetValue('MuteMode'));
        if ($muteMode) {
            $message = 'Abbruch, die Stummschaltung ist aktiv!';
            $this->SendDebug(__FUNCTION__, $message, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
        }
        return $muteMode;
    }
}