<?php
/**
 * Created by PhpStorm.
 * User: andrew
 * Date: 26/11/15
 * Time: 11:27 AM
 */
namespace Jimohalloran;
use Symfony\Component\Process\Process;

class CustomBackup extends Backup {

    protected function _prepareDbDump() {
        if (count($this->_config['database'])) {
            $this->_tmpDbPath = $this->_createFolder(self::DATABASE_FOLDER);
            foreach ($this->_config['database'] as $connectionInfo) {
                if(array_key_exists('working_dir', $connectionInfo) && array_key_exists('backup_command', $connectionInfo) && array_key_exists('name', $connectionInfo)) {
                    $this->_executeCustomDump($connectionInfo);
                } else {
                    throw new BackupException("Missing required db config settings");
                }
            }
        }
    }

    protected function _executeCustomDump($conn) {

        $cmd = str_replace("%", $this->_tmpDbPath . '/' . $conn['name'] . '.sql',$conn['backup_command']);

        // Create a flag file during database backup.  e.g. Create a maintenance.
        // flag file to put Magento into maintenance mode.
        if (array_key_exists('touch', $conn)) {
            touch($conn['touch']);
        }

        try {
            $process = new Process($cmd, $conn['working_dir']);
            $process->setTimeout(3600);
            $process->run();

            if (array_key_exists('touch', $conn) && file_exists($conn['touch'])) {
                unlink($conn['touch']);
            }
        } catch (\Exception $e) {
            // Catch any exception that might occur during command execution and
            // ensure the flag file is deleed before we re-throw the exception.
            if (array_key_exists('touch', $conn) && file_exists($conn['touch'])) {
                unlink($conn['touch']);
            }
            throw $e;
        }

        if (!$process->isSuccessful()) {
            throw new BackupException($process->getErrorOutput());
        }

    }
}