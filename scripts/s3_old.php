<?php

//Email recipient to send status email to after sync.
// define("MAIL_TO_RECIP", "matt@revolvercreative.com");

require_once('s3sdk/sdk.class.php');

class S3sync
{
    private $_s3;
    private $_startTime;
    private $_bucketName = '';
    private $_directory = '';
    private $_fileList = array();
    private $_fileHashList = array();
    private $_userBuckets = array();
    private $_filesUploaded = 0;
    private $_filesAlreadyUploaded = 0;
    private $_uploadErrors = 0;
    private $_s3Objects = array();
    private $_errorMessages = array();
    private $isDryRun = FALSE;
    const MAX_FILE_SIZE_IN_BYTES = 4294967296;

    public function __construct($bucketName, $directory, $dryRun = FALSE)
    {
        $this->_s3 = new AmazonS3();
        $this->_s3->disable_ssl_verification(false);
        $this->_startTime = microtime(true);
        $this->_bucketName = $bucketName;
        $this->_directory = $directory;
        $this->isDryRun = $dryRun;
        
        if( empty($bucketName) || empty($directory) )
            throw new Exception('Missing bucket or directory');
        else
        {
            echo "\n\nInitiated sync service with bucket {$this->_bucketName}\n";
        }

        $this->loadUsersBuckets();
        $this->checkBucketIsValid();

        if($this->isDryRun)
        {
            echo "WARNING: YOU ARE RUNNING IN DRY RUN MODE, NO FILES WILL BE UPLOADED TO S3.\n\n";
        }
        //Retreive a list of files to be processed
        $this->_fileList = $this->getFileListFromDirectory($this->_directory);
        $totalFileCount = count($this->_fileList);
        if($this->_fileList === FALSE)
            throw new Exception("Unable to get file list from directory.");
        else
            echo "\n\nTotal number of files found to process $totalFileCount \n";
    }

    public function sync()
    {
        $this->loadObjectsFromS3Bucket();

        echo "Begining to upload....\n\n";
        foreach($this->_fileList as $fileHash => $fileMeta)
        {
            if( ! isset($this->_s3Objects[$fileHash]) )
            {   
                $this->uploadFile($fileMeta);
            }
            else
            {
                echo "-";
                $this->_filesAlreadyUploaded++;
            }
        }
    }

    
    public function uploadFile($fileMeta)
    {
        echo ".";

        $fullPath = $fileMeta['path'];
        $fileHash = $fileMeta['hash'];
        
        echo $fileMeta['path'] . "\n";
        
        if( $this->_filesUploaded % 100 == 0  && $this->_filesUploaded != 0)
            echo "({$this->_filesUploaded} / " . count($this->_fileList) . ") \n";

        $options = array(
            'fileUpload' => $fullPath,
            'storage' => AmazonS3::STORAGE_REDUCED,
            'meta' => array(
                'path' => $fullPath,
                'hash' => $fileHash
            ),
        );

        if(!$this->isDryRun)
        {
            $response = $this->_s3->create_object($this->_bucketName, $fileHash, $options);

            if( $response->isOK() )
                $this->_filesUploaded++;
            else
                $this->_uploadErrors++;
        }
        else
            $this->_filesUploaded++;
    }


    public function __destruct()
    {
        $endTime = microtime(TRUE);
        $totalTime = $endTime - $this->_startTime;

        $out = array();
        $out[] = "************************* RESULTS ***********************\n ";
        $out[] = "Total time: $totalTime (s)\n";
        $out[] = "Total files examined: " . count($this->_fileList) . "\n";
        $out[] = "Total files uploaded to S3: {$this->_filesUploaded}\n";
        $out[] = "Total files ignored (cached in s3): {$this->_filesAlreadyUploaded}\n";
        $out[] = "Total upload errors: {$this->_uploadErrors}\n";
        $out[] = "***********************************************************\n ";

        if ( count($this->_errorMessages) > 0 )
        {
            $out[] = implode("\n", $this->_errorMessages);
        }

        $message = implode("\n", $out);
        echo $message;

        mail(MAIL_TO_RECIP, "S3 Sync Results", $message);
    }

    private function checkBucketIsValid()
    {
        //Make sure user specified a valid bucket.
        if( !isset($this->_userBuckets[$this->_bucketName]) )
        {
            echo "\nUnable to find the bucket specified in your bucket list.  Did you mean one of the following?\n\n";
            foreach($this->_userBuckets as $k => $v)
            {
                echo "\t" . $v . "\n";
            }
            echo "\n";
            exit;
        }
    }


    public function loadObjectsFromS3Bucket()
    {
        $response = $this->_s3->get_object_list($this->_bucketName);
        $results = array();

        foreach($response as $fileName)
        {
            $results[$fileName] = $fileName;
        }

        $this->_s3Objects = $results;
    }

    public function loadUsersBuckets()
    {
        $results = array();
        $response = $this->_s3->list_buckets();

        // Success?
        if(! $response->isOK() )
            throw new Exception("Unable to retrieve users buckets");

        $buckets = $response->body->Buckets->Bucket;

        foreach($buckets as $bucket)
        {
            $tmpName = (string) $bucket->Name;
            $results[$tmpName] = $tmpName;
        }

        $this->_userBuckets = $results;
    }

    public function getFileListFromDirectory($dir)
    {
        // array to hold return value
        $retval = array();
        // add trailing slash if missing
        if (substr($dir, -1) != "/")
            $dir .= "/";
        // open pointer to directory and read list of files
        $d = @dir($dir);

        if($d === FALSE)
            return FALSE;
        
        while (false !== ($entry = $d->read()))
        {
            // skip hidden files
            if ($entry[0] == ".")
                continue;

            if (is_dir("$dir$entry"))
            {
                if ( is_readable("$dir$entry/") )
                {
                    $retval = array_merge($retval, $this->getFileListFromDirectory("$dir$entry/", true) );
                }

            }
            elseif (is_readable("$dir$entry"))
            {
                $tFileName = "$dir$entry";
                // $hash = md5($tFileName);
                $hash = $tFileName;
                $size = filesize($tFileName);
                if($size > self::MAX_FILE_SIZE_IN_BYTES)
                {
                    $this->_errorMessages[] = "The following file will not be processed as it exceeds the max file size: $tFileName";
                    continue;
                }
                else
                {
                    $retval[$hash] = array('path' => $tFileName, 'file' => $entry, 'hash' => $hash);
                    if( isset($this->_fileHashList[$hash]) )
                    {
                        $this->_errorMessages[] = "WARNING: FOUND A HASH COLLISSION, DUPLICATE FILE:$tFileName ";
                    }
                    else
                    {
                        $this->_fileHashList[$hash] = 1;
                    }
                }
            }
        }
        $d->close();

        return $retval;

    }
}

if($argc != 3 && $argc != 4)
{
    echo "\nSync all files in a directory against an s3 instance.\n\n";
    echo "Usage: bucketName directoryName (dryrun - optional)\n\n";
    die();
}

$bucketName = $argv[1];
$directoryName = $argv[2];
$dryRun = isset($argv[3]) ? $argv[3] : FALSE;

$s = new S3sync($bucketName, $directoryName, $dryRun );
$s->sync();