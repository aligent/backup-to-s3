<?php

namespace Jimohalloran;
use Symfony\Component\Process\Process;

/**
 * Core backup class
 *
 * @author jim
 */
class Backup {
	const DATABASE_FOLDER = 'database';
	const FILE_FOLDER = 'files';
	
	protected $_config;
	
	protected $_tmpPath = false;
	protected $_tmpDbPath;
	protected $_tmpFilePath;
	protected $_tarball = false;
	
	public function __construct($yamlConfig) {
		$this->_config = $yamlConfig;
	}

	public function __destruct() {
		$this->_removeTmpFolder();
	}
	
	public function execute() {
		$this->_createTmpFolder();

		$this->_prepareDbDump();

		$this->_prepareFileDump();

		if (count($this->_config['files']) || count($this->_config['database'])) {
			$createTimestamp = array_key_exists('timestamp', $this->_config) && $this->_config['timestamp'] ? true : false;
			$this->_createTarball($this->_config['name'], $createTimestamp);

            if (array_key_exists('gpg', $this->_config) && array_key_exists('encryption_key', $this->_config['gpg']) && trim($this->_config['gpg']['encryption_key']) != '') {
                $this->_encryptBackup($this->_config['gpg']['encryption_key']);
            }

			$this->_uploadToAmazonS3($this->_config['amazon']);
		}
	}

	/**
	 * Downloads the dump file recorded in the backup.yml file
	 * TODO Currently only gets the first file matching <name>*. Add support to get the most recent instead
	 */
	public function downloadLatestDump() {
		$this->_downloadAmazonS3($this->_config['amazon']);
	}

	protected function _prepareDbDump() {
		if (count($this->_config['database'])) {
			$this->_tmpDbPath = $this->_createFolder(self::DATABASE_FOLDER);
			foreach ($this->_config['database'] as $connectionInfo) {
				$this->_mysqlDump($connectionInfo);
			}
		}
	}

	protected function _prepareFileDump() {
		if (count($this->_config['files'])) {
			$this->_tmpFilePath = $this->_createFolder(self::FILE_FOLDER);
			foreach ($this->_config['files'] as $conf) {
				$this->_copyFiles($conf);
			}
		}
	}

	protected function _downloadAmazonS3($awsConfig) {

		$s3 = new \AmazonS3(array(
			'key' => $awsConfig['access_key_id'],
			'secret' => $awsConfig['secret_access_key'],
		));

		$objects = $s3->get_object_list($awsConfig['bucket'], array('prefix' => 'jurlique-prod', 'max-keys' => 1));

		if(count($objects) > 0) {
			$response = $s3->get_object($awsConfig['bucket'], $objects[0]);
			file_put_contents($objects[0], $response->body);
			echo "Downloaded " . $objects[0] . " to the current directory.\n\n";
		}
	}
	
	protected function _uploadToAmazonS3($awsConfig) {
		$s3 = new \AmazonS3(array(
				'key' => $awsConfig['access_key_id'],
				'secret' => $awsConfig['secret_access_key'],
			));
	
		$numErrors = 0;
		$errMsg = '';
		$success = false;
		do {
			try {
				$response = $s3->create_mpu_object($awsConfig['bucket'], basename($this->_tarball), array(
						'fileUpload' => $this->_tarball,
						'acl' => \AmazonS3::ACL_PRIVATE ,
						'storage' => \AmazonS3::STORAGE_STANDARD,
						'partSize' => 1 * 1024 * 1024 * 1024,  // 1Gb
						'limit' => 1,
					));
				
				if ($response instanceof \CFResponse) {
					$success = $response->isOk();
				} elseif ($response instanceof \CFArray) {
					$success = $response->allOk();
				} else {
					$success = false;
					$errMsg = 'Unknown response type';
				}
				
				if (!$success) {
					throw new BackupException("Error uploading {$this->_tarball} to S3. Exception Message: '$errMsg' Response from Amazon was: ".print_r($response, true));
				}
				
			} catch (\cURL_Exception $e) {
				$numErrors++;
				$errMsg = $e->getMessage();
				echo "$numErrors: $errMsg\n";
			} catch (\cURL_Multi_Exception $e) {
				$numErrors++;
				$errMsg = $e->getMessage();
				echo "$numErrors: $errMsg\n";
			}
		} while ($numErrors < 3 && !$success);

	}

    protected function _encryptBackup($gpgKeyId) {
        $cmd = 'nice gpg ';
        $cmd .= ' --homedir '.$this->_elem($this->_config['gpg'], 'homedir', '~/.gnupg/');
        $cmd .= ' -r '.escapeshellarg($gpgKeyId).' -o '.$this->_tarball.'.gpg -e '.$this->_tarball;
        $process = new Process($cmd);
        $process->setTimeout(3600);
        $process->run();

        // If encryption succeeds, securely delete the original file.
        if ($process->isSuccessful()) {
            $cmd = 'shred --remove '.$this->_tarball;

            $process = new Process($cmd);
            $process->setTimeout(3600);
            $process->run();

            $this->_tarball .= '.gpg';
        } else {
            throw new BackupException("Error encrypting {$this->_tarball}: ".$process->getErrorOutput());
        }

    }

	protected function _createTarball($siteName, $createTimestamp = true) {

		$filename = sys_get_temp_dir().'/'.$siteName;
		if($createTimestamp) {
			$filename .= date('YmdHi');
		}
		$filename .= '.tar.gz';

		$this->_tarball = $filename;
		$cmd = 'nice tar zcf '. $this->_tarball . ' ' .$this->_tmpPath.'/';

		$process = new Process($cmd);
		$process->setTimeout(3600);
		$process->run();
		if (!$process->isSuccessful()) {
				throw new BackupException("Creating {$this->_tarball}: ".$process->getErrorOutput());
		}
	}
	
	protected function _copyFiles($conf) {
		$destDir = $this->_tmpFilePath.'/'.$conf['name'].'/';
		if (substr($conf['path'], -1) != '/') {
			$conf['path'] .= '/';
		}
		$cmd = 'nice cp -a -l '.$conf['path'].'* '.$destDir;
		
		mkdir($destDir, 0700);
				
		$process = new Process($cmd);
		$process->setTimeout(3600);
		$process->run();
		if (!$process->isSuccessful()) {
				throw new BackupException("Error copying {$conf['name']}: ".$process->getErrorOutput());
		}

        if (array_key_exists('exclude', $conf)) {
            if (!is_array($conf['exclude'])) {
                $conf['exclude'] = array($conf['exclude']);
            }

            foreach ($conf['exclude'] as $excludePath) {
                $fullExcludePath = $destDir.$excludePath;
                if (file_exists($fullExcludePath)) {
                    if (is_dir($fullExcludePath)) {
                        $this->_rrmdir($fullExcludePath);
                    } else {
                        unlink($fullExcludePath);
                    }
                }
            }
        }
	}
	
	protected function _mysqlDump($conn) {
		$cmd = 'nice mysqldump --routines';
		$cmd .= ' -h '.$this->_elem($conn, 'hostname', 'localhost');
		$cmd .= ' -u '.$this->_elem($conn, 'username', 'root');
		$cmd .= array_key_exists('password', $conn) ? ' -p' .$conn['password'] : '';
		$cmd .= ' ' . $this->_elem($conn, 'database', '') . ' > ' . $this->_tmpDbPath . '/' . $conn['name'] . '.sql';

		// Create a flag file during database backup.  e.g. Create a maintenance.
		// flag file to put Magento into maintenance mode.
		if (array_key_exists('touch', $conn)) {
			touch($conn['touch']);
		}

        try {
            $process = new Process($cmd);
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
	
	protected function _elem($array, $key, $default) {
		if (array_key_exists($key, $array)) {
			return $array[$key];
		} else {
			return $default;
		}
	}
	
	protected function _createFolder($suffix) {
		$fullPath = $this->_tmpPath.'/'.$suffix;
		mkdir($fullPath, 0700);
		return $fullPath;
	}
	
	protected function _createTmpFolder() {
		do {
			$path = sys_get_temp_dir().'/'.$this->_config['name'].mt_rand(0, 9999999);
		} while (!mkdir($path, 0700));
		return $this->_tmpPath = $path;
	}
	
	protected function _removeTmpFolder() {
		if ($this->_tmpPath !== false) {
			$this->_rrmdir($this->_tmpPath);
		}
		
		if ($this->_tarball !== false && file_exists($this->_tarball)) {
			unlink($this->_tarball);
		}
	}
	
	protected function _rrmdir($dir) {
		foreach(glob($dir . '/*') as $file) {
			if(is_dir($file)) {
				$this->_rrmdir($file);
			} else {
				unlink($file);
			}
		}
		// Deal specifically with hidden files.
		foreach(glob($dir . '/.?*') as $file) {
			if (strpos($file, '..') === false) {
				if(is_dir($file)) {
					$this->_rrmdir($file);
				} else {
					unlink($file);
				}
			}
		}
		
		rmdir($dir);
	}
	
}
