<?php
namespace OSS;

use OSS\Core\MimeTypes;
use OSS\Core\OssException;
use OSS\Http\RequestCore;
use OSS\Http\RequestCore_Exception;
use OSS\Http\ResponseCore;
use OSS\Result\BodyResult;
use OSS\Result\HeaderResult;
use OSS\Result\InitiateMultipartUploadResult;
use OSS\Result\ListMultipartUploadResult;
use OSS\Model\ListMultipartUploadInfo;
use OSS\Result\ListPartsResult;
use OSS\Result\PutSetDeleteResult;
use OSS\Result\DeleteObjectsResult;
use OSS\Result\CopyObjectResult;
use OSS\Result\CallbackResult;
use OSS\Result\AppendResult;
use OSS\Result\UploadPartResult;
use OSS\Core\OssUtil;
use OSS\Model\ListPartsInfo;

/**
 * Class OssClient
 *
 * Object Storage Service(OSS)'s client class, which wraps all OSS APIs user could call to talk to OSS.
 * Users could do operations on bucket, object, including MultipartUpload or setting ACL via an OSSClient instance.
 * For more details, please check out the OSS API document:https://www.alibabacloud.com/help/doc-detail/31947.htm
 */
class OssServer
{
    /**
     * Constructor
     *
     * There're a few different ways to create an OssClient object:
     * 1. Most common one from access Id, access Key and the endpoint: $ossClient = new OssClient($id, $key, $endpoint)
     * 2. If the endpoint is the CName (such as www.testoss.com, make sure it's CName binded in the OSS console),
     *    uses $ossClient = new OssClient($id, $key, $endpoint, true)
     * 3. If using Alicloud's security token service (STS), then the AccessKeyId, AccessKeySecret and STS token are all got from STS.
     * Use this: $ossClient = new OssClient($id, $key, $endpoint, false, $token)
     * 4. If the endpoint is in IP format, you could use this: $ossClient = new OssClient($id, $key, “1.2.3.4:8900”)
     *
     * @param string $accessKeyId The AccessKeyId from OSS or STS
     * @param string $accessKeySecret The AccessKeySecret from OSS or STS
     * @param string $endpoint The domain name of the datacenter,For example: oss-cn-hangzhou.aliyuncs.com
     * @param boolean $isCName If this is the CName and binded in the bucket.
     * @param string $securityToken from STS.
     * @param string $requestProxy
     * @throws OssException
     */
    public function __construct($accessKeyId, $accessKeySecret, $endpoint, $isCName = false, $securityToken = NULL, $requestProxy = NULL)
    {
        $accessKeyId = trim($accessKeyId);
        $accessKeySecret = trim($accessKeySecret);
        $endpoint = trim(trim($endpoint), "/");

        if (empty($accessKeyId)) {
            throw new OssException("access key id is empty");
        }
        if (empty($accessKeySecret)) {
            throw new OssException("access key secret is empty");
        }
        if (empty($endpoint)) {
            throw new OssException("endpoint is empty");
        }
        $this->hostname = $this->checkEndpoint($endpoint, $isCName);
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->securityToken = $securityToken;
        $this->requestProxy = $requestProxy;
        self::checkEnv();
    }

    /**
     * Uploads the $content object to OSS.
     * @param $bucket
     * @param $object
     * @param $content
     * @param null $options
     * @return null
     * @throws OssException
     * @throws RequestCore_Exception
     */
    public function putObjectSign($bucket, $object, $content, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);

        $options[self::OSS_CONTENT] = $content;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_OBJECT] = $object;

        if (!isset($options[self::OSS_LENGTH])) {
            $options[self::OSS_CONTENT_LENGTH] = strlen($options[self::OSS_CONTENT]);
        } else {
            $options[self::OSS_CONTENT_LENGTH] = $options[self::OSS_LENGTH];
        }

        $is_check_md5 = $this->isCheckMD5($options);
        if ($is_check_md5) {
            $content_md5 = base64_encode(md5($content, true));
            $options[self::OSS_CONTENT_MD5] = $content_md5;
        }

        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object);
        }
        return $this->auth($options);
    }

    /**
     * Uploads a local file
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $file local file path
     * @param array $options
     * @return null
     * @throws OssException
     */
    public function uploadFileSign($bucket, $object, $file, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        OssUtil::throwOssExceptionWithMessageIfEmpty($file, "file path is invalid");
        $file = OssUtil::encodePath($file);
        if (!file_exists($file)) {
            throw new OssException($file . " file does not exist");
        }
        $options[self::OSS_FILE_UPLOAD] = $file;
        $file_size = filesize($options[self::OSS_FILE_UPLOAD]);
        $is_check_md5 = $this->isCheckMD5($options);
        if ($is_check_md5) {
            $content_md5 = base64_encode(md5_file($options[self::OSS_FILE_UPLOAD], true));
            $options[self::OSS_CONTENT_MD5] = $content_md5;
        }
        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object, $file);
        }
        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_CONTENT_LENGTH] = $file_size;
        return $this->auth($options);
    }

    /**
     * Append the object with the content at the specified position.
     * The specified position is typically the lengh of the current file.
     * @param string $bucket bucket name
     * @param string $object objcet name
     * @param string $content content to append
     * @param array $options
     * @return int next append position
     * @throws OssException
     */
    public function appendObjectSign($bucket, $object, $content, $position, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);

        $options[self::OSS_CONTENT] = $content;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_METHOD] = self::OSS_HTTP_POST;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_SUB_RESOURCE] = 'append';
        $options[self::OSS_POSITION] = strval($position);

        if (!isset($options[self::OSS_LENGTH])) {
            $options[self::OSS_CONTENT_LENGTH] = strlen($options[self::OSS_CONTENT]);
        } else {
            $options[self::OSS_CONTENT_LENGTH] = $options[self::OSS_LENGTH];
        }

        $is_check_md5 = $this->isCheckMD5($options);
        if ($is_check_md5) {
            $content_md5 = base64_encode(md5($content, true));
            $options[self::OSS_CONTENT_MD5] = $content_md5;
        }

        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object);
        }
        return $this->auth($options);
    }

    /**
     * Append the object with a local file
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $file The local file path to append with
     * @param array $options
     * @return int next append position
     * @throws OssException
     */
    public function appendFile($bucket, $object, $file, $position, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);

        OssUtil::throwOssExceptionWithMessageIfEmpty($file, "file path is invalid");
        $file = OssUtil::encodePath($file);
        if (!file_exists($file)) {
            throw new OssException($file . " file does not exist");
        }
        $options[self::OSS_FILE_UPLOAD] = $file;
        $file_size = filesize($options[self::OSS_FILE_UPLOAD]);
        $is_check_md5 = $this->isCheckMD5($options);
        if ($is_check_md5) {
            $content_md5 = base64_encode(md5_file($options[self::OSS_FILE_UPLOAD], true));
            $options[self::OSS_CONTENT_MD5] = $content_md5;
        }
        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object, $file);
        }

        $options[self::OSS_METHOD] = self::OSS_HTTP_POST;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_CONTENT_LENGTH] = $file_size;
        $options[self::OSS_SUB_RESOURCE] = 'append';
        $options[self::OSS_POSITION] = strval($position);

        $response = $this->auth($options);
        $result = new AppendResult($response);
        return $result->getData();
    }

    /**
     * Copy from an existing OSS object to another OSS object. If the target object exists already, it will be overwritten.
     *
     * @param string $fromBucket Source bucket name
     * @param string $fromObject Source object name
     * @param string $toBucket Target bucket name
     * @param string $toObject Target object name
     * @param array $options
     * @return null
     * @throws OssException
     */
    public function copyObject($fromBucket, $fromObject, $toBucket, $toObject, $options = NULL)
    {
        $this->precheckCommon($fromBucket, $fromObject, $options);
        $this->precheckCommon($toBucket, $toObject, $options);
        $options[self::OSS_BUCKET] = $toBucket;
        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_OBJECT] = $toObject;
        if (isset($options[self::OSS_HEADERS])) {
            $options[self::OSS_HEADERS][self::OSS_OBJECT_COPY_SOURCE] = '/' . $fromBucket . '/' . $fromObject;
        } else {
            $options[self::OSS_HEADERS] = array(self::OSS_OBJECT_COPY_SOURCE => '/' . $fromBucket . '/' . $fromObject);
        }
        $response = $this->auth($options);
        $result = new CopyObjectResult($response);
        return $result->getData();
    }

    /**
     * Gets Object metadata
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $options Checks out the SDK document for the detail
     * @return array
     */
    public function getObjectMeta($bucket, $object, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_METHOD] = self::OSS_HTTP_HEAD;
        $options[self::OSS_OBJECT] = $object;
        $response = $this->auth($options);
        $result = new HeaderResult($response);
        return $result->getData();
    }

    /**
     * Deletes a object
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param array $options
     * @return null
     */
    public function deleteObject($bucket, $object, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_METHOD] = self::OSS_HTTP_DELETE;
        $options[self::OSS_OBJECT] = $object;
        $response = $this->auth($options);
        $result = new PutSetDeleteResult($response);
        return $result->getData();
    }

    /**
     * Deletes multiple objects in a bucket
     *
     * @param string $bucket bucket name
     * @param array $objects object list
     * @param array $options
     * @return ResponseCore
     * @throws null
     */
    public function deleteObjects($bucket, $objects, $options = null)
    {
        $this->precheckCommon($bucket, NULL, $options, false);
        if (!is_array($objects) || !$objects) {
            throw new OssException('objects must be array');
        }
        $options[self::OSS_METHOD] = self::OSS_HTTP_POST;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = '/';
        $options[self::OSS_SUB_RESOURCE] = 'delete';
        $options[self::OSS_CONTENT_TYPE] = 'application/xml';
        $quiet = 'false';
        if (isset($options['quiet'])) {
            if (is_bool($options['quiet'])) { //Boolean
                $quiet = $options['quiet'] ? 'true' : 'false';
            } elseif (is_string($options['quiet'])) { // string
                $quiet = ($options['quiet'] === 'true') ? 'true' : 'false';
            }
        }
        $xmlBody = OssUtil::createDeleteObjectsXmlBody($objects, $quiet);
        $options[self::OSS_CONTENT] = $xmlBody;
        $response = $this->auth($options);
        $result = new DeleteObjectsResult($response);
        return $result->getData();
    }

    /**
     * Gets Object content
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param array $options It must contain ALIOSS::OSS_FILE_DOWNLOAD. And ALIOSS::OSS_RANGE is optional and empty means to download the whole file.
     * @return string
     */
    public function getObject($bucket, $object, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_METHOD] = self::OSS_HTTP_GET;
        $options[self::OSS_OBJECT] = $object;
        if (isset($options[self::OSS_LAST_MODIFIED])) {
            $options[self::OSS_HEADERS][self::OSS_IF_MODIFIED_SINCE] = $options[self::OSS_LAST_MODIFIED];
            unset($options[self::OSS_LAST_MODIFIED]);
        }
        if (isset($options[self::OSS_ETAG])) {
            $options[self::OSS_HEADERS][self::OSS_IF_NONE_MATCH] = $options[self::OSS_ETAG];
            unset($options[self::OSS_ETAG]);
        }
        if (isset($options[self::OSS_RANGE])) {
            $range = $options[self::OSS_RANGE];
            $options[self::OSS_HEADERS][self::OSS_RANGE] = "bytes=$range";
            unset($options[self::OSS_RANGE]);
        }
        $response = $this->auth($options);
        $result = new BodyResult($response);
        return $result->getData();
    }

    /**
     * Gets the part size according to the preferred part size.
     * If the specified part size is too small or too big, it will return a min part or max part size instead.
     * Otherwise returns the specified part size.
     * @param int $partSize
     * @return int
     */
    private function computePartSize($partSize)
    {
        $partSize = (integer)$partSize;
        if ($partSize <= self::OSS_MIN_PART_SIZE) {
            $partSize = self::OSS_MIN_PART_SIZE;
        } elseif ($partSize > self::OSS_MAX_PART_SIZE) {
            $partSize = self::OSS_MAX_PART_SIZE;
        }
        return $partSize;
    }

    /**
     * Computes the parts count, size and start position according to the file size and the part size.
     * It must be only called by upload_Part().
     *
     * @param integer $file_size File size
     * @param integer $partSize part大小,part size. Default is 5MB
     * @return array An array contains key-value pairs--the key is `seekTo`and value is `length`.
     */
    public function generateMultiuploadParts($file_size, $partSize = 5242880)
    {
        $i = 0;
        $size_count = $file_size;
        $values = array();
        $partSize = $this->computePartSize($partSize);
        while ($size_count > 0) {
            $size_count -= $partSize;
            $values[] = array(
                self::OSS_SEEK_TO => ($partSize * $i),
                self::OSS_LENGTH => (($size_count > 0) ? $partSize : ($size_count + $partSize)),
            );
            $i++;
        }
        return $values;
    }

    /**
     * Initialize a multi-part upload
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param array $options Key-Value array
     * @throws OssException
     * @return string returns uploadid
     */
    public function initiateMultipartUpload($bucket, $object, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_POST;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_SUB_RESOURCE] = 'uploads';
        $options[self::OSS_CONTENT] = '';

        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object);
        }
        if (!isset($options[self::OSS_HEADERS])) {
            $options[self::OSS_HEADERS] = array();
        }
        $response = $this->auth($options);
        $result = new InitiateMultipartUploadResult($response);
        return $result->getData();
    }

    /**
     * Upload a part in a multiparts upload.
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $uploadId
     * @param array $options Key-Value array
     * @return string eTag
     * @throws OssException
     */
    public function uploadPart($bucket, $object, $uploadId, $options = null)
    {
        $this->precheckCommon($bucket, $object, $options);
        $this->precheckParam($options, self::OSS_FILE_UPLOAD, __FUNCTION__);
        $this->precheckParam($options, self::OSS_PART_NUM, __FUNCTION__);

        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_UPLOAD_ID] = $uploadId;

        if (isset($options[self::OSS_LENGTH])) {
            $options[self::OSS_CONTENT_LENGTH] = $options[self::OSS_LENGTH];
        }
        $response = $this->auth($options);
        $result = new UploadPartResult($response);
        return $result->getData();
    }

    /**
     * Gets the uploaded parts.
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $uploadId uploadId
     * @param array $options Key-Value array
     * @return ListPartsInfo
     * @throws OssException
     */
    public function listParts($bucket, $object, $uploadId, $options = null)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_GET;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_UPLOAD_ID] = $uploadId;
        $options[self::OSS_QUERY_STRING] = array();
        foreach (array('max-parts', 'part-number-marker') as $param) {
            if (isset($options[$param])) {
                $options[self::OSS_QUERY_STRING][$param] = $options[$param];
                unset($options[$param]);
            }
        }
        $response = $this->auth($options);
        $result = new ListPartsResult($response);
        return $result->getData();
    }

    /**
     * Abort a multiparts upload
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $uploadId uploadId
     * @param array $options Key-Value name
     * @return null
     * @throws OssException
     */
    public function abortMultipartUpload($bucket, $object, $uploadId, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_DELETE;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_UPLOAD_ID] = $uploadId;
        $response = $this->auth($options);
        $result = new PutSetDeleteResult($response);
        return $result->getData();
    }

    /**
     * Completes a multiparts upload, after all parts are uploaded.
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $uploadId uploadId
     * @param array $listParts array( array("PartNumber"=> int, "ETag"=>string))
     * @param array $options Key-Value array
     * @throws OssException
     * @return null
     */
    public function completeMultipartUpload($bucket, $object, $uploadId, $listParts, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_POST;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_UPLOAD_ID] = $uploadId;
        $options[self::OSS_CONTENT_TYPE] = 'application/xml';
        if (!is_array($listParts)) {
            throw new OssException("listParts must be array type");
        }
        $options[self::OSS_CONTENT] = OssUtil::createCompleteMultipartUploadXmlBody($listParts);
        $response = $this->auth($options);
        if (isset($options[self::OSS_CALLBACK]) && !empty($options[self::OSS_CALLBACK])) {
            $result = new CallbackResult($response);
        } else {
            $result = new PutSetDeleteResult($response);
        }
        return $result->getData();
    }

    /**
     * Lists all ongoing multipart upload events, which means all initialized but not completed or aborted multipart uploads.
     *
     * @param string $bucket bucket
     * @param array $options key-value array--expected keys are 'delimiter', 'key-marker', 'max-uploads', 'prefix', 'upload-id-marker'
     * @throws OssException
     * @return ListMultipartUploadInfo
     */
    public function listMultipartUploads($bucket, $options = null)
    {
        $this->precheckCommon($bucket, NULL, $options, false);
        $options[self::OSS_METHOD] = self::OSS_HTTP_GET;
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = '/';
        $options[self::OSS_SUB_RESOURCE] = 'uploads';

        foreach (array('delimiter', 'key-marker', 'max-uploads', 'prefix', 'upload-id-marker') as $param) {
            if (isset($options[$param])) {
                $options[self::OSS_QUERY_STRING][$param] = $options[$param];
                unset($options[$param]);
            }
        }
        $query = isset($options[self::OSS_QUERY_STRING]) ? $options[self::OSS_QUERY_STRING] : array();
        $options[self::OSS_QUERY_STRING] = array_merge(
            $query,
            array(self::OSS_ENCODING_TYPE => self::OSS_ENCODING_TYPE_URL)
        );

        $response = $this->auth($options);
        $result = new ListMultipartUploadResult($response);
        return $result->getData();
    }

    /**
     * Copy an existing file as a part
     *
     * @param string $fromBucket source bucket name
     * @param string $fromObject source object name
     * @param string $toBucket target bucket name
     * @param string $toObject target object name
     * @param int $partNumber Part number
     * @param string $uploadId Upload Id
     * @param array $options Key-Value array---it should have 'start' or 'end' key to specify the range of the source object to copy. If it's not specifed, the whole object is copied.
     * @return null
     * @throws OssException
     */
    public function uploadPartCopy($fromBucket, $fromObject, $toBucket, $toObject, $partNumber, $uploadId, $options = NULL)
    {
        $this->precheckCommon($fromBucket, $fromObject, $options);
        $this->precheckCommon($toBucket, $toObject, $options);

        //If $options['isFullCopy'] is not set, copy from the beginning
        $start_range = "0";
        if (isset($options['start'])) {
            $start_range = $options['start'];
        }
        $end_range = "";
        if (isset($options['end'])) {
            $end_range = $options['end'];
        }
        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_BUCKET] = $toBucket;
        $options[self::OSS_OBJECT] = $toObject;
        $options[self::OSS_PART_NUM] = $partNumber;
        $options[self::OSS_UPLOAD_ID] = $uploadId;

        if (!isset($options[self::OSS_HEADERS])) {
            $options[self::OSS_HEADERS] = array();
        }

        $options[self::OSS_HEADERS][self::OSS_OBJECT_COPY_SOURCE] = '/' . $fromBucket . '/' . $fromObject;
        $options[self::OSS_HEADERS][self::OSS_OBJECT_COPY_SOURCE_RANGE] = "bytes=" . $start_range . "-" . $end_range;
        $response = $this->auth($options);
        $result = new UploadPartResult($response);
        return $result->getData();
    }

    /**
     * A higher level API for uploading a file with multipart upload. It consists of initialization, parts upload and completion.
     *
     * @param string $bucket bucket name
     * @param string $object object name
     * @param string $file The local file to upload
     * @param array $options Key-Value array
     * @return null
     * @throws OssException
     */
    public function multiuploadFile($bucket, $object, $file, $options = null)
    {
        $this->precheckCommon($bucket, $object, $options);
        if (isset($options[self::OSS_LENGTH])) {
            $options[self::OSS_CONTENT_LENGTH] = $options[self::OSS_LENGTH];
            unset($options[self::OSS_LENGTH]);
        }
        if (empty($file)) {
            throw new OssException("parameter invalid, file is empty");
        }
        $uploadFile = OssUtil::encodePath($file);
        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object, $uploadFile);
        }

        $upload_position = isset($options[self::OSS_SEEK_TO]) ? (integer)$options[self::OSS_SEEK_TO] : 0;

        if (isset($options[self::OSS_CONTENT_LENGTH])) {
            $upload_file_size = (integer)$options[self::OSS_CONTENT_LENGTH];
        } else {
            $upload_file_size = filesize($uploadFile);
            if ($upload_file_size !== false) {
                $upload_file_size -= $upload_position;
            }
        }

        if ($upload_position === false || !isset($upload_file_size) || $upload_file_size === false || $upload_file_size < 0) {
            throw new OssException('The size of `fileUpload` cannot be determined in ' . __FUNCTION__ . '().');
        }
        // Computes the part size and assign it to options.
        if (isset($options[self::OSS_PART_SIZE])) {
            $options[self::OSS_PART_SIZE] = $this->computePartSize($options[self::OSS_PART_SIZE]);
        } else {
            $options[self::OSS_PART_SIZE] = self::OSS_MID_PART_SIZE;
        }

        $is_check_md5 = $this->isCheckMD5($options);
        // if the file size is less than part size, use simple file upload.
        if ($upload_file_size < $options[self::OSS_PART_SIZE] && !isset($options[self::OSS_UPLOAD_ID])) {
            return $this->uploadFile($bucket, $object, $uploadFile, $options);
        }

        // Using multipart upload, initialize if no OSS_UPLOAD_ID is specified in options.
        if (isset($options[self::OSS_UPLOAD_ID])) {
            $uploadId = $options[self::OSS_UPLOAD_ID];
        } else {
            // initialize
            $uploadId = $this->initiateMultipartUpload($bucket, $object, $options);
        }

        // generates the parts information and upload them one by one
        $pieces = $this->generateMultiuploadParts($upload_file_size, (integer)$options[self::OSS_PART_SIZE]);
        $response_upload_part = array();
        foreach ($pieces as $i => $piece) {
            $from_pos = $upload_position + (integer)$piece[self::OSS_SEEK_TO];
            $to_pos = (integer)$piece[self::OSS_LENGTH] + $from_pos - 1;
            $up_options = array(
                self::OSS_FILE_UPLOAD => $uploadFile,
                self::OSS_PART_NUM => ($i + 1),
                self::OSS_SEEK_TO => $from_pos,
                self::OSS_LENGTH => $to_pos - $from_pos + 1,
                self::OSS_CHECK_MD5 => $is_check_md5,
            );
            if ($is_check_md5) {
                $content_md5 = OssUtil::getMd5SumForFile($uploadFile, $from_pos, $to_pos);
                $up_options[self::OSS_CONTENT_MD5] = $content_md5;
            }
            $response_upload_part[] = $this->uploadPart($bucket, $object, $uploadId, $up_options);
        }

        $uploadParts = array();
        foreach ($response_upload_part as $i => $etag) {
            $uploadParts[] = array(
                'PartNumber' => ($i + 1),
                'ETag' => $etag,
            );
        }
        return $this->completeMultipartUpload($bucket, $object, $uploadId, $uploadParts);
    }

    /**
     * Uploads the local directory to the specified bucket into specified folder (prefix)
     *
     * @param string $bucket bucket name
     * @param string $prefix The object key prefix. Typically it's folder name. The name should not end with '/' as the API appends it automatically.
     * @param string $localDirectory The local directory to upload
     * @param string $exclude To excluded directories
     * @param bool $recursive Recursive flag. True: Recursively upload all datas under the local directory; False: only upload first layer's files.
     * @param bool $checkMd5
     * @return array Returns two list: array("succeededList" => array("object"), "failedList" => array("object"=>"errorMessage"))
     * @throws OssException
     */
    public function uploadDir($bucket, $prefix, $localDirectory, $exclude = '.|..|.svn|.git', $recursive = false, $checkMd5 = true)
    {
        $retArray = array("succeededList" => array(), "failedList" => array());
        if (empty($bucket)) throw new OssException("parameter error, bucket is empty");
        if (!is_string($prefix)) throw new OssException("parameter error, prefix is not string");
        if (empty($localDirectory)) throw new OssException("parameter error, localDirectory is empty");
        $directory = $localDirectory;
        $directory = OssUtil::encodePath($directory);
        //If it's not the local directory, throw OSSException.
        if (!is_dir($directory)) {
            throw new OssException('parameter error: ' . $directory . ' is not a directory, please check it');
        }
        //read directory
        $file_list_array = OssUtil::readDir($directory, $exclude, $recursive);
        if (!$file_list_array) {
            throw new OssException($directory . ' is empty...');
        }
        foreach ($file_list_array as $k => $item) {
            if (is_dir($item['path'])) {
                continue;
            }
            $options = array(
                self::OSS_PART_SIZE => self::OSS_MIN_PART_SIZE,
                self::OSS_CHECK_MD5 => $checkMd5,
            );
            $realObject = (!empty($prefix) ? $prefix . '/' : '') . $item['file'];

            try {
                $this->multiuploadFile($bucket, $realObject, $item['path'], $options);
                $retArray["succeededList"][] = $realObject;
            } catch (OssException $e) {
                $retArray["failedList"][$realObject] = $e->getMessage();
            }
        }
        return $retArray;
    }

    /**
     * Sign URL with specified expiration time in seconds (timeout) and HTTP method.
     * The signed URL could be used to access the object directly.
     *
     * @param string $bucket
     * @param string $object
     * @param int $timeout expiration time in seconds.
     * @param string $method
     * @param array $options Key-Value array
     * @return string
     * @throws OssException
     */
    public function signUrl($bucket, $object, $timeout = 60, $method = self::OSS_HTTP_GET, $options = NULL)
    {
        $this->precheckCommon($bucket, $object, $options);
        //method
        if (self::OSS_HTTP_GET !== $method && self::OSS_HTTP_PUT !== $method) {
            throw new OssException("method is invalid");
        }
        $options[self::OSS_BUCKET] = $bucket;
        $options[self::OSS_OBJECT] = $object;
        $options[self::OSS_METHOD] = $method;
        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = '';
        }
        $timeout = time() + $timeout;
        $options[self::OSS_PREAUTH] = $timeout;
        $options[self::OSS_DATE] = $timeout;
        $this->setSignStsInUrl(true);
        return $this->auth($options);
    }

    /**
     * validates options. Create a empty array if it's NULL.
     *
     * @param array $options
     * @throws OssException
     */
    private function precheckOptions(&$options)
    {
        OssUtil::validateOptions($options);
        if (!$options) {
            $options = array();
        }
    }

    /**
     * Validates bucket parameter
     *
     * @param string $bucket
     * @param string $errMsg
     * @throws OssException
     */
    private function precheckBucket($bucket, $errMsg = 'bucket is not allowed empty')
    {
        OssUtil::throwOssExceptionWithMessageIfEmpty($bucket, $errMsg);
    }

    /**
     * validates object parameter
     *
     * @param string $object
     * @throws OssException
     */
    private function precheckObject($object)
    {
        OssUtil::throwOssExceptionWithMessageIfEmpty($object, "object name is empty");
    }

    /**
     * 校验option restore
     *
     * @param string $restore
     * @throws OssException
     */
    private function precheckStorage($storage)
    {
        if (is_string($storage)) {
            switch ($storage) {
                case self::OSS_STORAGE_ARCHIVE:
                    return;
                case self::OSS_STORAGE_IA:
                    return;
                case self::OSS_STORAGE_STANDARD:
                    return;
                default:
                    break;
            }
        }
        throw new OssException('storage name is invalid');
    }

    /**
     * Validates bucket,options parameters and optionally validate object parameter.
     *
     * @param string $bucket
     * @param string $object
     * @param array $options
     * @param bool $isCheckObject
     */
    private function precheckCommon($bucket, $object, &$options, $isCheckObject = true)
    {
        if ($isCheckObject) {
            $this->precheckObject($object);
        }
        $this->precheckOptions($options);
        $this->precheckBucket($bucket);
    }

    /**
     * checks parameters
     *
     * @param array $options
     * @param string $param
     * @param string $funcName
     * @throws OssException
     */
    private function precheckParam($options, $param, $funcName)
    {
        if (!isset($options[$param])) {
            throw new OssException('The `' . $param . '` options is required in ' . $funcName . '().');
        }
    }

    /**
     * Checks md5
     *
     * @param array $options
     * @return bool|null
     */
    private function isCheckMD5($options)
    {
        return $this->getValue($options, self::OSS_CHECK_MD5, false, true, true);
    }

    /**
     * Gets value of the specified key from the options
     *
     * @param array $options
     * @param string $key
     * @param string $default
     * @param bool $isCheckEmpty
     * @param bool $isCheckBool
     * @return bool|null
     */
    private function getValue($options, $key, $default = NULL, $isCheckEmpty = false, $isCheckBool = false)
    {
        $value = $default;
        if (isset($options[$key])) {
            if ($isCheckEmpty) {
                if (!empty($options[$key])) {
                    $value = $options[$key];
                }
            } else {
                $value = $options[$key];
            }
            unset($options[$key]);
        }
        if ($isCheckBool) {
            if ($value !== true && $value !== false) {
                $value = false;
            }
        }
        return $value;
    }

    /**
     * Gets mimetype
     *
     * @param string $object
     * @return string
     */
    private function getMimeType($object, $file = null)
    {
        if (!is_null($file)) {
            $type = MimeTypes::getMimetype($file);
            if (!is_null($type)) {
                return $type;
            }
        }

        $type = MimeTypes::getMimetype($object);
        if (!is_null($type)) {
            return $type;
        }

        return self::DEFAULT_CONTENT_TYPE;
    }

    /**
     * Validates and executes the request according to OSS API protocol.
     *
     * @param array $options
     * @return string
     * @throws OssException
     * @throws RequestCore_Exception
     */
    private function auth($options)
    {
        OssUtil::validateOptions($options);
        //Validates bucket, not required for list_bucket
        $this->authPrecheckBucket($options);
        //Validates object
        $this->authPrecheckObject($options);
        //object name encoding must be UTF-8
        $this->authPrecheckObjectEncoding($options);
        //Validates ACL
        $this->authPrecheckAcl($options);
        // Should https or http be used?
        $scheme = $this->useSSL ? 'https://' : 'http://';
        // gets the host name. If the host name is public domain or private domain, form a third level domain by prefixing the bucket name on the domain name.
        $hostname = $this->generateHostname($options[self::OSS_BUCKET]);
        $string_to_sign = '';
        $headers = $this->generateHeaders($options, $hostname);
        $signable_query_string_params = $this->generateSignableQueryStringParam($options);
        $signable_query_string = OssUtil::toQueryString($signable_query_string_params);
        $resource_uri = $this->generateResourceUri($options);
        //Generates the URL (add query parameters)
        $conjunction = '?';
        $non_signable_resource = '';
        if (isset($options[self::OSS_SUB_RESOURCE])) {
            $conjunction = '&';
        }
        if ($signable_query_string !== '') {
            $signable_query_string = $conjunction . $signable_query_string;
            $conjunction = '&';
        }
        $query_string = $this->generateQueryString($options);
        if ($query_string !== '') {
            $non_signable_resource .= $conjunction . $query_string;
            $conjunction = '&';
        }
        $this->requestUrl = $scheme . $hostname . $resource_uri . $signable_query_string . $non_signable_resource;

        //Creates the request
        $request = new RequestCore($this->requestUrl, $this->requestProxy);
        $request->set_useragent($this->generateUserAgent());

        if (isset($options[self::OSS_METHOD])) {
            $string_to_sign .= $options[self::OSS_METHOD] . "\n";
        }

        if (isset($options[self::OSS_CONTENT])) {
            if ($headers[self::OSS_CONTENT_TYPE] === 'application/x-www-form-urlencoded') {
                $headers[self::OSS_CONTENT_TYPE] = 'application/octet-stream';
            }

            $headers[self::OSS_CONTENT_LENGTH] = strlen($options[self::OSS_CONTENT]);
            $headers[self::OSS_CONTENT_MD5] = base64_encode(md5($options[self::OSS_CONTENT], true));
        }

        if (isset($options[self::OSS_CALLBACK])) {
            $headers[self::OSS_CALLBACK] = base64_encode($options[self::OSS_CALLBACK]);
        }
        if (isset($options[self::OSS_CALLBACK_VAR])) {
            $headers[self::OSS_CALLBACK_VAR] = base64_encode($options[self::OSS_CALLBACK_VAR]);
        }

        if (!isset($headers[self::OSS_ACCEPT_ENCODING])) {
            $headers[self::OSS_ACCEPT_ENCODING] = '';
        }

        uksort($headers, 'strnatcasecmp');

        foreach ($headers as $header_key => $header_value) {
            $header_value = str_replace(array("\r", "\n"), '', $header_value);

            if (
                strtolower($header_key) === 'content-md5' ||
                strtolower($header_key) === 'content-type' ||
                strtolower($header_key) === 'date' ||
                (isset($options['self::OSS_PREAUTH']) && (integer)$options['self::OSS_PREAUTH'] > 0)
            ) {
                $string_to_sign .= $header_value . "\n";
            } elseif (substr(strtolower($header_key), 0, 6) === self::OSS_DEFAULT_PREFIX) {
                $string_to_sign .= strtolower($header_key) . ':' . $header_value . "\n";
            }
        }
        // Generates the signable_resource
        $signable_resource = $this->generateSignableResource($options);
        $string_to_sign .= rawurldecode($signable_resource) . urldecode($signable_query_string);

        // Sort the strings to be signed.
        $string_to_sign_ordered = $this->stringToSignSorted($string_to_sign);

        $signature = base64_encode(hash_hmac('sha1', $string_to_sign_ordered, $this->accessKeySecret, true));

        return 'OSS ' . $this->accessKeyId . ':' . $signature;
    }

    /**
     * Enaable/disable STS in the URL. This is to determine the $sts value passed from constructor take effect or not.
     *
     * @param boolean $enable
     */
    public function setSignStsInUrl($enable)
    {
        $this->enableStsInUrl = $enable;
    }

    /**
     * Validates bucket name--throw OssException if it's invalid
     *
     * @param $options
     * @throws OssException
     */
    private function authPrecheckBucket($options)
    {
        if (!(('/' == $options[self::OSS_OBJECT]) && ('' == $options[self::OSS_BUCKET]) && ('GET' == $options[self::OSS_METHOD])) && !OssUtil::validateBucket($options[self::OSS_BUCKET])) {
            throw new OssException('"' . $options[self::OSS_BUCKET] . '"' . 'bucket name is invalid');
        }
    }

    /**
     *
     * Validates the object name--throw OssException if it's invalid.
     *
     * @param $options
     * @throws OssException
     */
    private function authPrecheckObject($options)
    {
        if (isset($options[self::OSS_OBJECT]) && $options[self::OSS_OBJECT] === '/') {
            return;
        }

        if (isset($options[self::OSS_OBJECT]) && !OssUtil::validateObject($options[self::OSS_OBJECT])) {
            throw new OssException('"' . $options[self::OSS_OBJECT] . '"' . ' object name is invalid');
        }
    }

    /**
     * Checks the object's encoding. Convert it to UTF8 if it's in GBK or GB2312
     *
     * @param mixed $options parameter
     */
    private function authPrecheckObjectEncoding(&$options)
    {
        $tmp_object = $options[self::OSS_OBJECT];
        try {
            if (OssUtil::isGb2312($options[self::OSS_OBJECT])) {
                $options[self::OSS_OBJECT] = iconv('GB2312', "UTF-8//IGNORE", $options[self::OSS_OBJECT]);
            } elseif (OssUtil::checkChar($options[self::OSS_OBJECT], true)) {
                $options[self::OSS_OBJECT] = iconv('GBK', "UTF-8//IGNORE", $options[self::OSS_OBJECT]);
            }
        } catch (\Exception $e) {
            try {
                $tmp_object = iconv(mb_detect_encoding($tmp_object), "UTF-8", $tmp_object);
            } catch (\Exception $e) {
            }
        }
        $options[self::OSS_OBJECT] = $tmp_object;
    }

    /**
     * Checks if the ACL is one of the 3 predefined one. Throw OSSException if not.
     *
     * @param $options
     * @throws OssException
     */
    private function authPrecheckAcl($options)
    {
        if (isset($options[self::OSS_HEADERS][self::OSS_ACL]) && !empty($options[self::OSS_HEADERS][self::OSS_ACL])) {
            if (!in_array(strtolower($options[self::OSS_HEADERS][self::OSS_ACL]), self::$OSS_ACL_TYPES)) {
                throw new OssException($options[self::OSS_HEADERS][self::OSS_ACL] . ':' . 'acl is invalid(private,public-read,public-read-write)');
            }
        }
    }

    /**
     * Gets the host name for the current request.
     * It could be either a third level domain (prefixed by bucket name) or second level domain if it's CName or IP
     *
     * @param $bucket
     * @return string The host name without the protocol scheem (e.g. https://)
     */
    private function generateHostname($bucket)
    {
        if ($this->hostType === self::OSS_HOST_TYPE_IP) {
            $hostname = $this->hostname;
        } elseif ($this->hostType === self::OSS_HOST_TYPE_CNAME) {
            $hostname = $this->hostname;
        } else {
            // Private domain or public domain
            $hostname = ($bucket == '') ? $this->hostname : ($bucket . '.') . $this->hostname;
        }
        return $hostname;
    }

    /**
     * Gets the resource Uri in the current request
     *
     * @param $options
     * @return string return the resource uri.
     */
    private function generateResourceUri($options)
    {
        $resource_uri = "";

        // resource_uri + bucket
        if (isset($options[self::OSS_BUCKET]) && '' !== $options[self::OSS_BUCKET]) {
            if ($this->hostType === self::OSS_HOST_TYPE_IP) {
                $resource_uri = '/' . $options[self::OSS_BUCKET];
            }
        }

        // resource_uri + object
        if (isset($options[self::OSS_OBJECT]) && '/' !== $options[self::OSS_OBJECT]) {
            $resource_uri .= '/' . str_replace(array('%2F', '%25'), array('/', '%'), rawurlencode($options[self::OSS_OBJECT]));
        }

        // resource_uri + sub_resource
        $conjunction = '?';
        if (isset($options[self::OSS_SUB_RESOURCE])) {
            $resource_uri .= $conjunction . $options[self::OSS_SUB_RESOURCE];
        }
        return $resource_uri;
    }

    /**
     * Generates the signalbe query string parameters in array type
     *
     * @param array $options
     * @return array
     */
    private function generateSignableQueryStringParam($options)
    {
        $signableQueryStringParams = array();
        $signableList = array(
            self::OSS_PART_NUM,
            'response-content-type',
            'response-content-language',
            'response-cache-control',
            'response-content-encoding',
            'response-expires',
            'response-content-disposition',
            self::OSS_UPLOAD_ID,
            self::OSS_COMP,
            self::OSS_LIVE_CHANNEL_STATUS,
            self::OSS_LIVE_CHANNEL_START_TIME,
            self::OSS_LIVE_CHANNEL_END_TIME,
            self::OSS_PROCESS,
            self::OSS_POSITION,
            self::OSS_SYMLINK,
            self::OSS_RESTORE,
        );

        foreach ($signableList as $item) {
            if (isset($options[$item])) {
                $signableQueryStringParams[$item] = $options[$item];
            }
        }

        if ($this->enableStsInUrl && (!is_null($this->securityToken))) {
            $signableQueryStringParams["security-token"] = $this->securityToken;
        }

        return $signableQueryStringParams;
    }

    /**
     *  Generates the resource uri for signing
     *
     * @param mixed $options
     * @return string
     */
    private function generateSignableResource($options)
    {
        $signableResource = "";
        $signableResource .= '/';
        if (isset($options[self::OSS_BUCKET]) && '' !== $options[self::OSS_BUCKET]) {
            $signableResource .= $options[self::OSS_BUCKET];
            // if there's no object in options, adding a '/' if the host type is not IP.\
            if ($options[self::OSS_OBJECT] == '/') {
                if ($this->hostType !== self::OSS_HOST_TYPE_IP) {
                    $signableResource .= "/";
                }
            }
        }
        //signable_resource + object
        if (isset($options[self::OSS_OBJECT]) && '/' !== $options[self::OSS_OBJECT]) {
            $signableResource .= '/' . str_replace(array('%2F', '%25'), array('/', '%'), rawurlencode($options[self::OSS_OBJECT]));
        }
        if (isset($options[self::OSS_SUB_RESOURCE])) {
            $signableResource .= '?' . $options[self::OSS_SUB_RESOURCE];
        }
        return $signableResource;
    }

    /**
     * generates query string
     *
     * @param mixed $options
     * @return string
     */
    private function generateQueryString($options)
    {
        //query parameters
        $queryStringParams = array();
        if (isset($options[self::OSS_QUERY_STRING])) {
            $queryStringParams = array_merge($queryStringParams, $options[self::OSS_QUERY_STRING]);
        }
        return OssUtil::toQueryString($queryStringParams);
    }

    private function stringToSignSorted($string_to_sign)
    {
        $queryStringSorted = '';
        $explodeResult = explode('?', $string_to_sign);
        $index = count($explodeResult);
        if ($index === 1)
            return $string_to_sign;

        $queryStringParams = explode('&', $explodeResult[$index - 1]);
        sort($queryStringParams);

        foreach($queryStringParams as $params)
        {
            $queryStringSorted .= $params . '&';
        }

        $queryStringSorted = substr($queryStringSorted, 0, -1);

        return $explodeResult[0] . '?' . $queryStringSorted;
    }

    /**
     * Initialize headers
     *
     * @param mixed $options
     * @param string $hostname hostname
     * @return array
     */
    private function generateHeaders($options, $hostname)
    {
        $headers = array(
            self::OSS_CONTENT_MD5 => '',
            self::OSS_CONTENT_TYPE => isset($options[self::OSS_CONTENT_TYPE]) ? $options[self::OSS_CONTENT_TYPE] : self::DEFAULT_CONTENT_TYPE,
            self::OSS_DATE => isset($options[self::OSS_DATE]) ? $options[self::OSS_DATE] : gmdate('D, d M Y H:i:s \G\M\T'),
            self::OSS_HOST => $hostname,
        );
        if (isset($options[self::OSS_CONTENT_MD5])) {
            $headers[self::OSS_CONTENT_MD5] = $options[self::OSS_CONTENT_MD5];
        }

        //Add stsSecurityToken
        if ((!is_null($this->securityToken)) && (!$this->enableStsInUrl)) {
            $headers[self::OSS_SECURITY_TOKEN] = $this->securityToken;
        }
        //Merge HTTP headers
        if (isset($options[self::OSS_HEADERS])) {
            $headers = array_merge($headers, $options[self::OSS_HEADERS]);
        }
        return $headers;
    }

    /**
     * Generates UserAgent
     *
     * @return string
     */
    private function generateUserAgent()
    {
        return self::OSS_NAME . "/" . self::OSS_VERSION . " (" . php_uname('s') . "/" . php_uname('r') . "/" . php_uname('m') . ";" . PHP_VERSION . ")";
    }

    /**
     * Checks endpoint type and returns the endpoint without the protocol schema.
     * Figures out the domain's type (ip, cname or private/public domain).
     *
     * @param string $endpoint
     * @param boolean $isCName
     * @return string The domain name without the protocol schema.
     */
    private function checkEndpoint($endpoint, $isCName)
    {
        $ret_endpoint = null;
        if (strpos($endpoint, 'http://') === 0) {
            $ret_endpoint = substr($endpoint, strlen('http://'));
        } elseif (strpos($endpoint, 'https://') === 0) {
            $ret_endpoint = substr($endpoint, strlen('https://'));
            $this->useSSL = true;
        } else {
            $ret_endpoint = $endpoint;
        }

        if ($isCName) {
            $this->hostType = self::OSS_HOST_TYPE_CNAME;
        } elseif (OssUtil::isIPFormat($ret_endpoint)) {
            $this->hostType = self::OSS_HOST_TYPE_IP;
        } else {
            $this->hostType = self::OSS_HOST_TYPE_NORMAL;
        }
        return $ret_endpoint;
    }

    /**
     * Check if all dependent extensions are installed correctly.
     * For now only "curl" is needed.
     * @throws OssException
     */
    public static function checkEnv()
    {
        if (function_exists('get_loaded_extensions')) {
            //Test curl extension
            $enabled_extension = array("curl");
            $extensions = get_loaded_extensions();
            if ($extensions) {
                foreach ($enabled_extension as $item) {
                    if (!in_array($item, $extensions)) {
                        throw new OssException("Extension {" . $item . "} is not installed or not enabled, please check your php env.");
                    }
                }
            } else {
                throw new OssException("function get_loaded_extensions not found.");
            }
        } else {
            throw new OssException('Function get_loaded_extensions has been disabled, please check php config.');
        }
    }

    // Constants for Life cycle
    const OSS_LIFECYCLE_EXPIRATION = "Expiration";
    const OSS_LIFECYCLE_TIMING_DAYS = "Days";
    const OSS_LIFECYCLE_TIMING_DATE = "Date";
    //OSS Internal constants
    const OSS_BUCKET = 'bucket';
    const OSS_OBJECT = 'object';
    const OSS_HEADERS = OssUtil::OSS_HEADERS;
    const OSS_METHOD = 'method';
    const OSS_QUERY = 'query';
    const OSS_BASENAME = 'basename';
    const OSS_MAX_KEYS = 'max-keys';
    const OSS_UPLOAD_ID = 'uploadId';
    const OSS_PART_NUM = 'partNumber';
    const OSS_COMP = 'comp';
    const OSS_LIVE_CHANNEL_STATUS = 'status';
    const OSS_LIVE_CHANNEL_START_TIME = 'startTime';
    const OSS_LIVE_CHANNEL_END_TIME = 'endTime';
    const OSS_POSITION = 'position';
    const OSS_MAX_KEYS_VALUE = 100;
    const OSS_MAX_OBJECT_GROUP_VALUE = OssUtil::OSS_MAX_OBJECT_GROUP_VALUE;
    const OSS_MAX_PART_SIZE = OssUtil::OSS_MAX_PART_SIZE;
    const OSS_MID_PART_SIZE = OssUtil::OSS_MID_PART_SIZE;
    const OSS_MIN_PART_SIZE = OssUtil::OSS_MIN_PART_SIZE;
    const OSS_FILE_SLICE_SIZE = 8192;
    const OSS_PREFIX = 'prefix';
    const OSS_DELIMITER = 'delimiter';
    const OSS_MARKER = 'marker';
    const OSS_ACCEPT_ENCODING = 'Accept-Encoding';
    const OSS_CONTENT_MD5 = 'Content-Md5';
    const OSS_SELF_CONTENT_MD5 = 'x-oss-meta-md5';
    const OSS_CONTENT_TYPE = 'Content-Type';
    const OSS_CONTENT_LENGTH = 'Content-Length';
    const OSS_IF_MODIFIED_SINCE = 'If-Modified-Since';
    const OSS_IF_UNMODIFIED_SINCE = 'If-Unmodified-Since';
    const OSS_IF_MATCH = 'If-Match';
    const OSS_IF_NONE_MATCH = 'If-None-Match';
    const OSS_CACHE_CONTROL = 'Cache-Control';
    const OSS_EXPIRES = 'Expires';
    const OSS_PREAUTH = 'preauth';
    const OSS_CONTENT_COING = 'Content-Coding';
    const OSS_CONTENT_DISPOSTION = 'Content-Disposition';
    const OSS_RANGE = 'range';
    const OSS_ETAG = 'etag';
    const OSS_LAST_MODIFIED = 'lastmodified';
    const OS_CONTENT_RANGE = 'Content-Range';
    const OSS_CONTENT = OssUtil::OSS_CONTENT;
    const OSS_BODY = 'body';
    const OSS_LENGTH = OssUtil::OSS_LENGTH;
    const OSS_HOST = 'Host';
    const OSS_DATE = 'Date';
    const OSS_AUTHORIZATION = 'Authorization';
    const OSS_FILE_DOWNLOAD = 'fileDownload';
    const OSS_FILE_UPLOAD = 'fileUpload';
    const OSS_PART_SIZE = 'partSize';
    const OSS_SEEK_TO = 'seekTo';
    const OSS_SIZE = 'size';
    const OSS_QUERY_STRING = 'query_string';
    const OSS_SUB_RESOURCE = 'sub_resource';
    const OSS_DEFAULT_PREFIX = 'x-oss-';
    const OSS_CHECK_MD5 = 'checkmd5';
    const DEFAULT_CONTENT_TYPE = 'application/octet-stream';
    const OSS_SYMLINK_TARGET = 'x-oss-symlink-target';
    const OSS_SYMLINK = 'symlink';
    const OSS_HTTP_CODE = 'http_code';
    const OSS_REQUEST_ID = 'x-oss-request-id';
    const OSS_INFO = 'info';
    const OSS_STORAGE = 'storage';
    const OSS_RESTORE = 'restore';
    const OSS_STORAGE_STANDARD = 'Standard';
    const OSS_STORAGE_IA = 'IA';
    const OSS_STORAGE_ARCHIVE = 'Archive';

    //private URLs
    const OSS_URL_ACCESS_KEY_ID = 'OSSAccessKeyId';
    const OSS_URL_EXPIRES = 'Expires';
    const OSS_URL_SIGNATURE = 'Signature';
    //HTTP METHOD
    const OSS_HTTP_GET = 'GET';
    const OSS_HTTP_PUT = 'PUT';
    const OSS_HTTP_HEAD = 'HEAD';
    const OSS_HTTP_POST = 'POST';
    const OSS_HTTP_DELETE = 'DELETE';
    const OSS_HTTP_OPTIONS = 'OPTIONS';
    //Others
    const OSS_ACL = 'x-oss-acl';
    const OSS_OBJECT_ACL = 'x-oss-object-acl';
    const OSS_OBJECT_GROUP = 'x-oss-file-group';
    const OSS_MULTI_PART = 'uploads';
    const OSS_MULTI_DELETE = 'delete';
    const OSS_OBJECT_COPY_SOURCE = 'x-oss-copy-source';
    const OSS_OBJECT_COPY_SOURCE_RANGE = "x-oss-copy-source-range";
    const OSS_PROCESS = "x-oss-process";
    const OSS_CALLBACK = "x-oss-callback";
    const OSS_CALLBACK_VAR = "x-oss-callback-var";
    //Constants for STS SecurityToken
    const OSS_SECURITY_TOKEN = "x-oss-security-token";
    const OSS_ACL_TYPE_PRIVATE = 'private';
    const OSS_ACL_TYPE_PUBLIC_READ = 'public-read';
    const OSS_ACL_TYPE_PUBLIC_READ_WRITE = 'public-read-write';
    const OSS_ENCODING_TYPE = "encoding-type";
    const OSS_ENCODING_TYPE_URL = "url";

    // Domain Types
    const OSS_HOST_TYPE_NORMAL = "normal";//http://bucket.oss-cn-hangzhou.aliyuncs.com/object
    const OSS_HOST_TYPE_IP = "ip";  //http://1.1.1.1/bucket/object
    const OSS_HOST_TYPE_SPECIAL = 'special'; //http://bucket.guizhou.gov/object
    const OSS_HOST_TYPE_CNAME = "cname";  //http://mydomain.com/object
    //OSS ACL array
    static $OSS_ACL_TYPES = array(
        self::OSS_ACL_TYPE_PRIVATE,
        self::OSS_ACL_TYPE_PUBLIC_READ,
        self::OSS_ACL_TYPE_PUBLIC_READ_WRITE
    );
    // OssClient version information
    const OSS_NAME = "aliyun-sdk-php";
    const OSS_VERSION = "2.3.0";
    const OSS_BUILD = "20180105";
    const OSS_AUTHOR = "";
    const OSS_OPTIONS_ORIGIN = 'Origin';
    const OSS_OPTIONS_REQUEST_METHOD = 'Access-Control-Request-Method';
    const OSS_OPTIONS_REQUEST_HEADERS = 'Access-Control-Request-Headers';

    //use ssl flag
    private $useSSL = false;
    private $maxRetries = 3;
    private $redirects = 0;

    // user's domain type. It could be one of the four: OSS_HOST_TYPE_NORMAL, OSS_HOST_TYPE_IP, OSS_HOST_TYPE_SPECIAL, OSS_HOST_TYPE_CNAME
    private $hostType = self::OSS_HOST_TYPE_NORMAL;
    private $requestUrl;
    private $requestProxy = null;
    private $accessKeyId;
    private $accessKeySecret;
    private $hostname;
    private $securityToken;
    private $enableStsInUrl = false;
    private $timeout = 0;
    private $connectTimeout = 0;
}
