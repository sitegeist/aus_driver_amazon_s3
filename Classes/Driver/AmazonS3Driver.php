<?php
namespace AUS\AusDriverAmazonS3\Driver;

use \TYPO3\CMS\Core\Utility\GeneralUtility;
use \TYPO3\CMS\Core\Resource\ResourceStorage;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Markus Hölzle <m.hoelzle@andersundsehr.com>, anders und sehr GmbH
 *  All rights reserved
 *
 ***************************************************************/

/**
 * Class AmazonS3Driver
 * Driver for Amazon Simple Storage Service (S3).
 *
 * @author Markus Hölzle <m.hoelzle@andersundsehr.com>
 * @package AUS\AusDriverAmazonS3\Driver
 */
class AmazonS3Driver extends \TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver {


	const DEBUG_MODE = FALSE;

	const DRIVER_TYPE = 'AusDriverAmazonS3';

	const EXTENSION_KEY = 'aus_driver_amazon_s3';

	const FILTER_ALL = 'all';

	const FILTER_FOLDERS = 'folders';

	const FILTER_FILES = 'files';

	const ROOT_FOLDER_IDENTIFIER = '/';

	/**
	 * @var \Aws\S3\S3Client
	 */
	protected $s3Client;

	/**
	 * The base URL that points to this driver's storage. As long is this is not set, it is assumed that this folder
	 * is not publicly available
	 *
	 * @var string
	 */
	protected $baseUrl;

	/**
	 * The identifier map used for renaming
	 *
	 * @var array
	 */
	protected $identifierMap;

	/**
	 * Object existence is cached here like:
	 * $identifier => TRUE|FALSE
	 *
	 * @var array
	 */
	protected $objectExistenceCache = array();

	/**
	 * Object permissions are cached here in subarrays like:
	 * $identifier => array('r' => \boolean, 'w' => \boolean)
	 *
	 * @var array
	 */
	protected $objectPermissionsCache = array();

	/**
	 * Processing folder
	 *
	 * @var string
	 */
	protected $processingFolder;

	/**
	 * Default processing folder
	 *
	 * @var string
	 */
	protected $processingFolderDefault = '_processed_';

	/**
	 * @var \TYPO3\CMS\Core\Resource\ResourceStorage
	 */
	protected $storage;

	/**
	 * @var array
	 */
	protected static $settings = NULL;



	/**
	 * loadExternalClasses
	 */
	public static function loadExternalClasses(){
		require_once(GeneralUtility::getFileAbsFileName('EXT:' . self::EXTENSION_KEY . '/Resources/Private/PHP/Aws/aws-autoloader.php'));
	}



	/**
	 * @return void
	 */
	public function processConfiguration() {}


	/**
	 * @return void
	 */
	public function initialize() {
		$this->initializeBaseUrl()
			->initializeSettings()
			->initializeClient();
		$this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE | ResourceStorage::CAPABILITY_PUBLIC | ResourceStorage::CAPABILITY_WRITABLE;
	}


	/**
	 * @param string $identifier
	 * @return string
	 */
	public function getPublicUrl($identifier) {
		return $this->baseUrl . '/' . $identifier;
	}


	/**
	 * Creates a (cryptographic) hash for a file.
	 *
	 * @param string $fileIdentifier
	 * @param string $hashAlgorithm
	 * @return string
	 */
	public function hash($fileIdentifier, $hashAlgorithm) {
		return $this->hashIdentifier($fileIdentifier);
	}


	/**
	 * Returns the identifier of the default folder new files should be put into.
	 *
	 * @return string
	 */
	public function getDefaultFolder() {
		return $this->getRootLevelFolder();
	}


	/**
	 * Returns the identifier of the root level folder of the storage.
	 *
	 * @return string
	 */
	public function getRootLevelFolder() {
		return '/';
	}


	/**
	 * Returns information about a file.
	 *
	 * @param string $fileIdentifier
	 * @param array  $propertiesToExtract Array of properties which are be extracted
	 *                                    If empty all will be extracted
	 * @return array
	 */
	public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = array()) {
		$this->normalizeIdentifier($fileIdentifier);
		$metadata = $this->s3Client->headObject(array(
			'Bucket' => $this->configuration['bucket'],
			'Key' => $fileIdentifier
		))->toArray();
		$lastModified = \DateTime::createFromFormat(\Aws\Common\Enum\DateFormat::RFC2822, $metadata['LastModified']);
		$lastModifiedUnixTimestamp = $lastModified->getTimestamp();

		return array(
			'name' => basename($fileIdentifier),
			'identifier' => $fileIdentifier,
			'ctime' => $lastModifiedUnixTimestamp,
			'mtime' => $lastModifiedUnixTimestamp,
			'mimetype' => $metadata['ContentType'],
			'size' => (integer)$metadata['ContentLength'],
			'identifier_hash' => $this->hashIdentifier($fileIdentifier),
			'folder_hash' => $this->hashIdentifier(\TYPO3\CMS\Core\Utility\PathUtility::dirname($fileIdentifier)),
			'storage' => $this->storageUid
		);
	}


	/**
	 * Checks if a file exists
	 *
	 * @param \string $identifier
	 * @return \bool
	 */
	public function fileExists($identifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($identifier, 'Hello from ' . __METHOD__); }
		if (substr($identifier, -1) === '/') {
			return FALSE;
		}
		return $this->objectExists($identifier);
	}


	/**
	 * Checks if a folder exists
	 *
	 * @param \string $identifier
	 * @return \boolean
	 */
	public function folderExists($identifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($identifier, 'Hello from ' . __METHOD__); }
		if ($identifier === self::ROOT_FOLDER_IDENTIFIER) {
			return TRUE;
		}
		if (substr($identifier, -1) !== '/') {
			$identifier .= '/';
		}
		return $this->objectExists($identifier);
	}


	/**
	 * @param string $fileName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function fileExistsInFolder($fileName, $folderIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileName => $folderIdentifier), 'Hello from ' . __METHOD__); }
		return $this->objectExists($folderIdentifier . $fileName);
	}


	/**
	 * Checks if a folder exists inside a storage folder
	 *
	 * @param string $folderName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, $folderIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($folderName => $folderIdentifier), 'Hello from ' . __METHOD__); }
		return $this->objectExists($folderIdentifier . $folderName . '/');
	}


	/**
	 * @param string  $localFilePath  (within PATH_site)
	 * @param string  $targetFolderIdentifier
	 * @param string  $newFileName    optional, if not given original name is used
	 * @param boolean $removeOriginal if set the original file will be removed
	 *                                after successful operation
	 * @return string the identifier of the new file
	 */
	public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = TRUE) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($localFilePath, $targetFolderIdentifier, $newFileName, $removeOriginal), 'Hello from ' . __METHOD__); }
		$targetIdentifier = $targetFolderIdentifier . $newFileName;
		if (is_uploaded_file($localFilePath)) {
			$moveResult = file_put_contents($this->getStreamWrapperPath($targetIdentifier), file_get_contents($localFilePath));
		} else {
			$localIdentifier = $localFilePath;
			$this->normalizeIdentifier($localIdentifier);

			if ($this->objectExists($localIdentifier)) {
				$moveResult = rename($this->getStreamWrapperPath($localIdentifier), $this->getStreamWrapperPath($targetIdentifier));
			} else {
				$moveResult = file_put_contents($this->getStreamWrapperPath($targetIdentifier), file_get_contents($localFilePath));
			}
		}

		return $targetIdentifier;
	}


	/**
	 * @param string $fileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName
	 *
	 * @return string
	 */
	public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileIdentifier, $targetFolderIdentifier, $newFileName), 'Hello from ' . __METHOD__); }
		$targetIdentifier = $targetFolderIdentifier . $newFileName;
		$this->renameObject($fileIdentifier, $targetIdentifier);
		return $targetIdentifier;
	}


	/**
	 * Copies a file *within* the current storage.
	 * Note that this is only about an inner storage copy action,
	 * where a file is just copied to another folder in the same storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $fileName
	 * @return string the Identifier of the new file
	 */
	public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileIdentifier, $targetFolderIdentifier, $fileName), 'Hello from ' . __METHOD__); }
		$targetIdentifier = $targetFolderIdentifier . $fileName;
		$this->copyObject($fileIdentifier, $targetIdentifier);
		return $targetIdentifier;
	}


	/**
	 * Replaces a file with file in local file system.
	 *
	 * @param string $fileIdentifier
	 * @param string $localFilePath
	 * @return boolean TRUE if the operation succeeded
	 * @todo implement this
	 */
	public function replaceFile($fileIdentifier, $localFilePath) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileIdentifier, $localFilePath), 'Hello from ' . __METHOD__); }
		die();
	}


	/**
	 * Removes a file from the filesystem. This does not check if the file is
	 * still used or if it is a bad idea to delete it for some other reason
	 * this has to be taken care of in the upper layers (e.g. the Storage)!
	 *
	 * @param string $fileIdentifier
	 * @return boolean TRUE if deleting the file succeeded
	 */
	public function deleteFile($fileIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($fileIdentifier, 'Hello from ' . __METHOD__); }
		$this->deleteObject($fileIdentifier);
	}


	/**
	 * Removes a folder in filesystem.
	 *
	 * @param string  $folderIdentifier
	 * @param boolean $deleteRecursively
	 * @return boolean
	 */
	public function deleteFolder($folderIdentifier, $deleteRecursively = FALSE) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($folderIdentifier, $deleteRecursively), 'Hello from ' . __METHOD__); }
		if ($deleteRecursively) {
			$items = $this->s3Client->listObjects(array(
				'Bucket' => $this->configuration['bucket'],
				'Prefix' => $folderIdentifier
			))->toArray();

			foreach ($items['Contents'] as $object) {
				// Filter the folder itself
				if ($object['Key'] !== $folderIdentifier) {
					if ($this->isDir($object['Key'])) {
						$subFolder = $this->getFolder($object['Key']);
						if ($subFolder) {
							$this->deleteFolder($subFolder, $deleteRecursively);
						}
					} else {
						unlink($this->getStreamWrapperPath($object['Key']));
					}
				}
			}
		}

		$this->deleteObject($folderIdentifier);
	}


	/**
	 * Returns a path to a local copy of a file for processing it. When changing the
	 * file, you have to take care of replacing the current version yourself!
	 *
	 * @param string $fileIdentifier
	 * @param bool   $writable Set this to FALSE if you only need the file for read
	 *                         operations. This might speed up things, e.g. by using
	 *                         a cached local version. Never modify the file if you
	 *                         have set this flag!
	 * @return string The path to the file on the local disk
	 * @throws \RuntimeException
	 * @todo take care of replacing the file on change
	 */
	public function getFileForLocalProcessing($fileIdentifier, $writable = TRUE) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileIdentifier, $writable), 'Hello from ' . __METHOD__); }

		$sourcePath = $this->getStreamWrapperPath($fileIdentifier);
		$temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
		$result = copy($sourcePath, $temporaryPath);
		if ($result === FALSE) {
			throw new \RuntimeException('Copying file ' . $fileIdentifier . ' to temporary path failed.', 1320577649);
		}
		return $temporaryPath;
	}


	/**
	 * Creates a new (empty) file and returns the identifier.
	 *
	 * @param string $fileName
	 * @param string $parentFolderIdentifier
	 * @return string
	 */
	public function createFile($fileName, $parentFolderIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileName => $parentFolderIdentifier), 'Hello from ' . __METHOD__); }
		$identifier = $parentFolderIdentifier . $fileName;
		$this->createObject($identifier);
		return $identifier;
	}


	/**
	 * Creates a folder, within a parent folder.
	 * If no parent folder is given, a root level folder will be created
	 *
	 * @param string  $newFolderName
	 * @param string  $parentFolderIdentifier
	 * @param boolean $recursive
	 * @return string the Identifier of the new folder
	 * @toDo: implement $recursive
	 */
	public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = FALSE) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($newFolderName, $parentFolderIdentifier, $recursive), 'Hello from ' . __METHOD__); }
		$newFolderName = trim($newFolderName, '/');

		$identifier = $parentFolderIdentifier . $newFolderName . '/';
		$this->createObject($identifier);
		return $identifier;
	}


	/**
	 * Returns the contents of a file. Beware that this requires to load the
	 * complete file into memory and also may require fetching the file from an
	 * external location. So this might be an expensive operation (both in terms
	 * of processing resources and money) for large files.
	 *
	 * @param string $fileIdentifier
	 * @return string The file contents
	 */
	public function getFileContents($fileIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($fileIdentifier, 'Hello from ' . __METHOD__); }

		$result = $this->s3Client->getObject(array(
			'Bucket' => $this->configuration['bucket'],
			'Key' => $fileIdentifier
		));
		return (string)$result['Body'];
	}


	/**
	 * Sets the contents of a file to the specified value.
	 *
	 * @param string $fileIdentifier
	 * @param string $contents
	 * @return integer The number of bytes written to the file
	 */
	public function setFileContents($fileIdentifier, $contents) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileIdentifier, $contents), 'Hello from ' . __METHOD__); }
		return file_put_contents($this->getStreamWrapperPath($fileIdentifier), $contents);
	}


	/**
	 * Renames a file in this storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $newName The target path (including the file name!)
	 * @return string The identifier of the file after renaming
	 */
	public function renameFile($fileIdentifier, $newName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($fileIdentifier, $newName), 'Hello from ' . __METHOD__); }
		$newIdentifier = $fileIdentifier;
		$namePivot = strrpos($newIdentifier, basename($fileIdentifier));
		$newIdentifier = substr($newIdentifier, 0, $namePivot) . $newName;

		$this->renameObject($fileIdentifier, $newIdentifier);
		return $newIdentifier;
	}


	/**
	 * Renames a folder in this storage.
	 *
	 * @param string $folderIdentifier
	 * @param string $newName
	 * @return array A map of old to new file identifiers of all affected resources
	 */
	public function renameFolder($folderIdentifier, $newName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($folderIdentifier, $newName), 'Hello from ' . __METHOD__); }
		$this->resetIdentifierMap();

		$parentFolderName = dirname($folderIdentifier);
		if ($parentFolderName === '.') {
			$parentFolderName = '';
		} else {
			$parentFolderName .= '/';
		}
		$newIdentifier = $parentFolderName . $newName . '/';

		foreach ($this->getSubObjects($folderIdentifier, FALSE) as $object) {
			$subObjectIdentifier = $object['Key'];
			if ($this->isDir($subObjectIdentifier)) {
				$this->renameSubFolder($this->getFolder($subObjectIdentifier), $newIdentifier);
			} else {
				$newSubObjectIdentifier = $newIdentifier . basename($subObjectIdentifier);
				$this->renameObject($subObjectIdentifier, $newSubObjectIdentifier);
			}
		}

		$this->renameObject($folderIdentifier, $newIdentifier);
		return $this->identifierMap;
	}


	/**
	 * Folder equivalent to moveFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 *
	 * @return array All files which are affected, map of old => new file identifiers
	 */
	public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName), 'Hello from ' . __METHOD__); }
		$this->resetIdentifierMap();

		$newIdentifier = $targetFolderIdentifier . $newFolderName . '/';
		$this->renameObject($sourceFolderIdentifier, $newIdentifier);

		$subObjects = $this->getSubObjects($sourceFolderIdentifier);
		$this->sortObjectsForNestedFolderOperations($subObjects);

		foreach ($subObjects as $subObject) {
			$newIdentifier = $targetFolderIdentifier . $newFolderName . '/' . substr($subObject['Key'], strlen($sourceFolderIdentifier));
			$this->renameObject($subObject['Key'], $newIdentifier);
		}
		return $this->identifierMap;
	}


	/**
	 * Folder equivalent to copyFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 *
	 * @return boolean
	 */
	public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName), 'Hello from ' . __METHOD__); }

		$newIdentifier = $targetFolderIdentifier . $newFolderName . '/';
		$this->copyObject($sourceFolderIdentifier, $newIdentifier);

		$subObjects = $this->getSubObjects($sourceFolderIdentifier);
		$this->sortObjectsForNestedFolderOperations($subObjects);

		foreach ($subObjects as $subObject) {
			$newIdentifier = $targetFolderIdentifier . $newFolderName . '/' . substr($subObject['Key'], strlen($sourceFolderIdentifier));
			$this->copyObject($subObject['Key'], $newIdentifier);
		}

		return TRUE;
	}


	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param string $folderIdentifier
	 * @return boolean TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty($folderIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($folderIdentifier, 'Hello from ' . __METHOD__); }
		$result = $this->s3Client->listObjects(array(
			'Bucket' => $this->configuration['bucket'],
			'Prefix' => $folderIdentifier
		))->toArray();

		// Contents will always include the folder itself
		if (sizeof($result['Contents']) > 1) {
			return FALSE;
		}
		return TRUE;
	}


	/**
	 * Checks if a given identifier is within a container, e.g. if
	 * a file or folder is within another folder.
	 * This can e.g. be used to check for web-mounts.
	 *
	 * Hint: this also needs to return TRUE if the given identifier
	 * matches the container identifier to allow access to the root
	 * folder of a filemount.
	 *
	 * @param string $folderIdentifier
	 * @param string $identifier identifier to be checked against $folderIdentifier
	 * @return boolean TRUE if $content is within or matches $folderIdentifier
	 */
	public function isWithin($folderIdentifier, $identifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($folderIdentifier, $identifier), 'Hello from ' . __METHOD__);}

		$folderIdentifier = $this->canonicalizeAndCheckFileIdentifier($folderIdentifier);
		$entryIdentifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
		if ($folderIdentifier === $entryIdentifier) {
			return TRUE;
		}
		// File identifier canonicalization will not modify a single slash so
		// we must not append another slash in that case.
		if ($folderIdentifier !== '/') {
			$folderIdentifier .= '/';
		}

		return GeneralUtility::isFirstPartOfStr($entryIdentifier, $folderIdentifier);
	}


	/**
	 * Returns information about a file.
	 *
	 * @param string $folderIdentifier
	 * @return array
	 */
	public function getFolderInfoByIdentifier($folderIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($folderIdentifier, 'Hello from ' . __METHOD__);}
		$this->normalizeIdentifier($folderIdentifier);

		return array(
			'identifier' => $folderIdentifier,
			'name' => basename(rtrim($folderIdentifier, '/')),
			'storage' => $this->storageUid
		);
	}


	/**
	 * Returns a list of files inside the specified path
	 *
	 * @param string  $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array   $filenameFilterCallbacks callbacks for filtering the items
	 *
	 * @return array of FileIdentifiers
	 * @toDo: Implement params
	 */
	public function getFilesInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $filenameFilterCallbacks = array()) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($folderIdentifier, $start, $numberOfItems, $recursive, $filenameFilterCallbacks), 'Hello from ' . __METHOD__);}

		$this->normalizeIdentifier($folderIdentifier);
		$files = array();
		if ($folderIdentifier === self::ROOT_FOLDER_IDENTIFIER) {
			$folderIdentifier = '';
		}

		$response = $this->s3Client->listObjects(array(
			'Bucket' => $this->configuration['bucket'],
			'Prefix' => $folderIdentifier
		))->toArray();

		if($response['Contents']) {
			foreach ($response['Contents'] as $fileCandidate) {
				// skip directory entries
				if (substr($fileCandidate['Key'], -1) === '/') {
					continue;
				}

				// skip subdirectory entries
				if (!$recursive && substr_count($fileCandidate['Key'], '/') > substr_count($folderIdentifier, '/')) {
					continue;
				}

				$fileName = basename($fileCandidate['Key']);
				$files[$fileCandidate['Key']] = $fileCandidate['Key'];
			}
		}

		return $files;
	}


	/**
	 * Returns a list of folders inside the specified path
	 *
	 * @param string  $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array   $folderNameFilterCallbacks callbacks for filtering the items
	 *
	 * @return array of Folder Identifier
	 * @toDo: Implement params
	 */
	public function getFoldersInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $folderNameFilterCallbacks = array()) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($folderIdentifier, $start, $numberOfItems, $recursive, $folderNameFilterCallbacks), 'Hello from ' . __METHOD__); }

		$this->normalizeIdentifier($folderIdentifier);
		$folders = array();

		$configuration = array('Bucket' => $this->configuration['bucket']);
		if ($folderIdentifier === self::ROOT_FOLDER_IDENTIFIER) {
			$configuration['Delimiter'] = $folderIdentifier;
			$response = $this->s3Client->listObjects($configuration)->toArray();
			if($response['CommonPrefixes']){
				foreach ($response['CommonPrefixes'] as $folderCandidate) {
					$key = $folderCandidate['Prefix'];
					$folderName = basename(rtrim($key, '/'));
					if ($folderName !== $this->getProcessingFolder()) {
						$folders[$key] = $key;
					}
				}
			}
		} else {
			foreach ($this->getSubObjects($folderIdentifier, FALSE, self::FILTER_FOLDERS) as $folderObject) {
				$key = $folderObject['Key'];
				$folders[$key] = $key;
			}
		}

		return $folders;
	}


	/**
	 * Directly output the contents of the file to the output
	 * buffer. Should not take care of header files or flushing
	 * buffer before. Will be taken care of by the Storage.
	 *
	 * @param string $identifier
	 * @return void
	 * @toDo: Implement
	 */
	public function dumpFileContents($identifier) {
		throw new \Exception('Not implemented');
	}


	/**
	 * Returns the permissions of a file/folder as an array
	 * (keys r, w) of boolean flags
	 *
	 * @param string $identifier
	 * @return array
	 */
	public function getPermissions($identifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($identifier, 'Hello from ' . __METHOD__); }
		return $this->getObjectPermissions($identifier);
	}


	/**
	 * Merges the capabilites merged by the user at the storage
	 * configuration into the actual capabilities of the driver
	 * and returns the result.
	 *
	 * @param integer $capabilities
	 *
	 * @return integer
	 */
	public function mergeConfigurationCapabilities($capabilities) {
		$this->capabilities &= $capabilities;
		return $this->capabilities;
	}






	/*************************************************************/
	/****************** Protected Helpers ************************/
	/*************************************************************/

	/**
	 * initializeBaseUrl
	 *
	 * @return $this
	 */
	protected function initializeBaseUrl() {
		$protocol = $this->configuration['protocol'];
		if($protocol == 'auto'){
			$protocol = GeneralUtility::getIndpEnv('TYPO3_SSL') ? 'https://' : 'http://';
		}
		$this->baseUrl = $protocol;

		if (isset($this->configuration['publicBaseUrl']) && $this->configuration['publicBaseUrl'] !== '') {
			$this->baseUrl .= $this->configuration['publicBaseUrl'];
		} else {
			$this->baseUrl .= $this->configuration['bucket'] . '.s3.amazonaws.com';
		}
		return $this;
	}


	/**
	 * initializeSettings
	 *
	 * @return $this
	 */
	protected function initializeSettings() {
		if(self::$settings === NULL) {
			self::$settings =  unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][self::EXTENSION_KEY]);
			if(!isset(self::$settings['doNotLoadAmazonLib']) || !self::$settings['doNotLoadAmazonLib']) {
				self::loadExternalClasses();
			}
			if(TYPO3_MODE === 'FE' && (!isset(self::$settings['dnsPrefetch']) || self::$settings['dnsPrefetch'])) {
				$GLOBALS['TSFE']->additionalHeaderData['ausDriverAmazonS3_dnsPrefetch'] = '<link rel="dns-prefetch" href="' . $this->baseUrl . '">';
			}
		}
		return $this;
	}


	/**
	 * initializeClient
	 *
	 * @return $this
	 */
	protected function initializeClient() {
		$reflectionRegion = new \ReflectionClass('\Aws\Common\Enum\Region');
		$configuration = array(
			'key' => $this->configuration['key'],
			'secret' => $this->configuration['secretKey'],
			'region' => $reflectionRegion->getConstant($this->configuration['region'])
		);

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXTENSION_KEY]['initializeClient-preProcessing'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXTENSION_KEY]['initializeClient-preProcessing'] as $funcName) {
				$params = array('s3Client' => &$this->s3Client, 'configuration' => &$configuration);
				GeneralUtility::callUserFunction($funcName, $params, $this);
			}
		}

		if(!$this->s3Client) {
			$this->s3Client = \Aws\S3\S3Client::factory($configuration);
			\Aws\S3\StreamWrapper::register($this->s3Client);
		}
		return $this;
	}


	/**
	 * Checks if an object exists
	 *
	 * @param \string $identifier
	 * @return \boolean
	 */
	protected function objectExists($identifier) {
		$this->normalizeIdentifier($identifier);
		if (!isset($this->objectExistenceCache[$identifier])) {
			try {
				$result = $this->s3Client->doesObjectExist($this->configuration['bucket'], $identifier);
			}
			catch (\Exception $exc) {
				echo $exc->getTraceAsString();
				$result = FALSE;
			}
			$this->objectExistenceCache[$identifier] = $result;
		}
		return $this->objectExistenceCache[$identifier];
	}


	/**
	 * @param string $identifier
	 * @return mixed
	 */
	protected function getObjectPermissions($identifier) {
		if (!isset($this->objectPermissionsCache[$identifier])) {
			if ($identifier === self::ROOT_FOLDER_IDENTIFIER) {
				$permissions = array('r' => TRUE, 'w' => TRUE,);
			} else {
				$permissions = array('r' => FALSE, 'w' => FALSE,);

				$response = $this->s3Client->getObjectAcl(array(
					'Bucket' => $this->configuration['bucket'],
					'Key' => $identifier
				))->toArray();

				// Until the SDK provides any useful information about folder permissions, we take full access for granted as long as one user with full access exists.
				foreach ($response['Grants'] as $grant) {
					if ($grant['Permission'] === \Aws\S3\Enum\Permission::FULL_CONTROL) {
						$permissions['r'] = TRUE;
						$permissions['w'] = TRUE;
					}
				}
			}
			$this->objectPermissionsCache[$identifier] = $permissions;
		}

		return $this->objectPermissionsCache[$identifier];
	}


	/**
	 * @param string $identifier
	 * @return Model
	 */
	protected function deleteObject($identifier) {
		return $this->s3Client->deleteObject(array('Bucket' => $this->configuration['bucket'], 'Key' => $identifier));
	}


	/**
	 * Returns a folder by its identifier.
	 *
	 * @param $identifier
	 * @return \TYPO3\CMS\Core\Resource\Folder
	 */
	protected function getFolder($identifier) {
		if ($identifier === self::ROOT_FOLDER_IDENTIFIER) {
			return $this->getRootLevelFolder();
		}
		$this->normalizeIdentifier($identifier);
		return new \TYPO3\CMS\Core\Resource\Folder($this->storage, $identifier, basename(rtrim($identifier, '/')));
	}


	/**
	 * @param \string $identifier
	 * @return void
	 */
	protected function createObject($identifier) {
		$this->s3Client->putObject(array(
			'Bucket' => $this->configuration['bucket'],
			'Key' => $identifier,
			'Body' => ' '
		));
	}


	/**
	 * Renames an object using the StreamWrapper
	 *
	 * @param \string $identifier
	 * @param \string $newIdentifier
	 * @return void
	 */
	protected function renameObject($identifier, $newIdentifier) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($identifier, $newIdentifier), 'Hello from ' . __METHOD__); }
		rename($this->getStreamWrapperPath($identifier), $this->getStreamWrapperPath($newIdentifier));
		$this->identifierMap[$identifier] = $newIdentifier;
	}


	/**
	 * Returns the StreamWrapper path of a file or folder.
	 *
	 * @param \TYPO3\CMS\Core\Resource\FileInterface|\TYPO3\CMS\Core\Resource\Folder|string $file
	 * @return string
	 * @throws \RuntimeException
	 */
	protected function getStreamWrapperPath($file) {
		$basePath = 's3://' . $this->configuration['bucket'] . '/';
		if ($file instanceof \TYPO3\CMS\Core\Resource\FileInterface) {
			$identifier = $file->getIdentifier();
		} elseif ($file instanceof \TYPO3\CMS\Core\Resource\Folder) {
			$identifier = $file->getIdentifier();
		} elseif (is_string($file)) {
			$identifier = $file;
		} else {
			throw new \RuntimeException('Type "' . gettype($file) . '" is not supported.', 1325191178);
		}
		$this->normalizeIdentifier($identifier);
		return $basePath . $identifier;
	}


	/**
	 * @param \string &$identifier
	 */
	protected function normalizeIdentifier(&$identifier) {
		if ($identifier !== '/') {
			$identifier = ltrim($identifier, '/');
		}
	}


	/**
	 * @return void
	 */
	protected function resetIdentifierMap() {
		$this->identifierMap = array();
	}


	/**
	 * Returns all sub objects for the parent object given by identifier, excluding the parent object itself.
	 * If the $recursive flag is disabled, only objects on the exact next level are returned.
	 *
	 * @param string  $identifier
	 * @param boolean $recursive
	 * @param string  $filter
	 * @return array
	 */
	protected function getSubObjects($identifier, $recursive = TRUE, $filter = self::FILTER_ALL) {
		$result = $this->s3Client->listObjects(array(
			'Bucket' => $this->configuration['bucket'],
			'Prefix' => $identifier
		))->toArray();

		return array_filter($result['Contents'], function (&$object) use ($identifier, $recursive, $filter) {
			return ($object['Key'] !== $identifier && ($recursive || substr_count(trim(str_replace($identifier, '', $object['Key']), '/'), '/') === 0) && ($filter === self::FILTER_ALL || $filter === self::FILTER_FOLDERS && $this->isDir($object['Key']) || $filter === self::FILTER_FILES && !$this->isDir($object['Key'])));
		});
	}


	/**
	 * Renames a given subfolder by renaming all its sub objects and the folder itself.
	 * Used for renaming child objects of a renamed a parent object.
	 *
	 * @param \TYPO3\CMS\Core\Resource\Folder $folder
	 * @param \string                         $newDirName The new directory name the folder will reside in
	 * @return void
	 */
	protected function renameSubFolder(\TYPO3\CMS\Core\Resource\Folder $folder, $newDirName) {
		if (self::DEBUG_MODE) { \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(array($newDirName => $folder), 'Hello from ' . __METHOD__); }
		foreach ($this->getSubObjects($folder->getIdentifier(), FALSE) as $subObject) {
			$subObjectIdentifier = $subObject['Key'];
			if ($this->isDir($subObjectIdentifier)) {
				$subFolder = $this->getFolder($subObjectIdentifier);
				$this->renameSubFolder($subFolder, $newDirName . $folder->getName() . '/');
			} else {
				$newSubObjectIdentifier = $newDirName . $folder->getName() . '/' . basename($subObjectIdentifier);
				$this->renameObject($subObjectIdentifier, $newSubObjectIdentifier);
			}
		}

		$newIdentifier = $newDirName . $folder->getName() . '/';
		$this->renameObject($folder->getIdentifier(), $newIdentifier);
	}


	/**
	 * @param \string $identifier
	 * @param \string $targetIdentifier
	 */
	protected function copyObject($identifier, $targetIdentifier) {
		$this->s3Client->copyObject(array(
			'Bucket' => $this->configuration['bucket'],
			'CopySource' => $this->configuration['bucket'] . '/' . $identifier,
			'Key' => $targetIdentifier
		));
	}


	/**
	 * @param array $objects S3 Objects as arrays with at least the Key field set
	 * @return void
	 */
	protected function sortObjectsForNestedFolderOperations(array& $objects) {
		usort($objects, function ($object1, $object2) {
			if (substr($object1['Key'], -1) === '/') {
				if (substr($object2['Key'], -1) === '/') {
					$numSlashes1 = substr_count($object1['Key'], '/');
					$numSlashes2 = substr_count($object2['Key'], '/');
					return $numSlashes1 < $numSlashes2 ? -1 : ($numSlashes1 === $numSlashes2 ? 0 : 1);
				} else {
					return -1;
				}
			} else {
				if (substr($object2['Key'], -1) === '/') {
					return 1;
				} else {
					$numSlashes1 = substr_count($object1['Key'], '/');
					$numSlashes2 = substr_count($object2['Key'], '/');
					return $numSlashes1 < $numSlashes2 ? -1 : ($numSlashes1 === $numSlashes2 ? 0 : 1);
				}
			}
		});
	}


	/**
	 * @return ResourceStorage
	 */
	protected function getStorage(){
		if(!$this->storage){
			/** @var $storageRepository \TYPO3\CMS\Core\Resource\StorageRepository */
			$storageRepository = GeneralUtility::makeInstance('TYPO3\CMS\Core\Resource\StorageRepository');
			$this->storage = $storageRepository->findByUid($this->storageUid);
		}
		return $this->storage;
	}


	protected function getProcessingFolder(){
		if(!$this->processingFolder){
			$confProcessingFolder = $this->getStorage()->getProcessingFolder()->getName();
			$this->processingFolder = $confProcessingFolder ? $confProcessingFolder : $this->processingFolderDefault;
		}
		return $this->processingFolder;
	}


	/**
	 * Returns whether the object defined by its identifier is a folder
	 *
	 * @param string $identifier
	 * @return boolean
	 */
	protected function isDir($identifier) {
		return substr($identifier, -1) === '/';
	}

}