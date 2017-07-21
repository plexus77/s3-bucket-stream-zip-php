<?php
/**
* @author Jaisen Mathai <jaisen@jmathai.com>
* @copyright Copyright 2015, Jaisen Mathai
*
* This library streams the contents from an Amazon S3 bucket
*  without needing to store the files on disk or download
*  all of the files before starting to send the archive.
*
* Example usage can be found in the examples folder.
*/

namespace JMathai\S3BucketStreamZip;

use Aws\S3\S3Client;
use JMathai\S3BucketStreamZip\Exception\InvalidParameterException;
use ZipStream;

class S3BucketStreamZip
{
    /**
     * @var array
     *
     * http://docs.aws.amazon.com/AmazonS3/latest/API/RESTBucketGET.html
     *
     * {
     *   key: access key,
     *   secret: access secret
     *   bucket: bucket name
     *   region: bucket region
     *   prefix: prefix
     * }
     */
    private $params = [];

    /**
     * @var object
     */
    private $s3Client;

    /**
     * Create a new ZipStream object.
     *
     * @param array $params - AWS key, secret, region, and list object parameters
     */
    public function __construct($params)
    {
        foreach (['key', 'secret', 'bucket', 'region'] as $key) {
            if (!isset($params[$key])) {
                throw new InvalidParameterException('$params parameter to constructor requires a `'.$key.'` attribute');
            }
        }

        $this->params = $params;

        $this->s3Client = new S3Client(
            [
                'region'      => $this->params['region'],
                'version'     => 'latest',
                'credentials' => [
                'key'    => $this->params['key'],
                'secret' => $this->params['secret'],
            ],
        ]);
    }

    /**
     * Stream a zip file to the client.
     *
     * @param string $filename - Name for the file to be sent to the client
     * @param array  $params   - Optional parameters
     *                         {
     *                         expiration: '+10 minutes'
     *                         }
     */
    public function send($filename, $params = [])
    {
        // Set default values for the optional $params argument
        if (!isset($params['expiration'])) {
            $params['expiration'] = '+10 minutes';
        }

        // Initialize the ZipStream object and pass in the file name which
        //  will be what is sent in the content-disposition header.
        // This is the name of the file which will be sent to the client.
        $zip = new ZipStream\ZipStream($filename);

        // Get a list of objects from the S3 bucket. The iterator is a high
        //  level abstration that will fetch ALL of the objects without having
        //  to manually loop over responses.
        $result = $this->s3Client->getIterator('ListObjects', [
            'Bucket' => $this->params['bucket'],
            'Prefix' => $this->params['prefix'],
        ]);

        // We loop over each object from the ListObjects call.
        foreach ($result as $file) {
            // We need to use a command to get a request for the S3 object
            //  and then we can get the presigned URL.
            $command = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->params['bucket'],
                'Key'    => $file['Key'],
            ]);
            $signedUrl = (string) $this->s3Client->createPresignedRequest($command, $params['expiration'])->getUri();

            // Get the file name on S3 so we can save it to the zip file
            //  using the same name.
            $fileName = substr($file['Key'], strlen($this->params['prefix']));

            // We want to fetch the file to a file pointer so we create it here
            //  and create a curl request and store the response into the file
            //  pointer.
            // After we've fetched the file we add the file to the zip file using
            //  the file pointer and then we close the curl request and the file
            //  pointer.
            // Closing the file pointer removes the file.
            $fp = tmpfile();
            $ch = curl_init($signedUrl);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fseek($fp,0);
            $zip->addFileFromStream($fileName, $fp);
            fclose($fp);
        }

        // Finalize the zip file.
        $zip->finish();
    }
}
