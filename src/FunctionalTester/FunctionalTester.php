<?php
namespace FunctionalTester;

use Guzzle\Http\Message\Response;

class FunctionalTester
{
    /**
     * @var array
     */
    protected $env = [];

    /**
     * @var string
     */
    protected $documentRoot;

    /**
     * @var string
     */
    protected $includePath;

    /**
     * @var array
     */
    protected $phpOptions = [];

    /**
     * @var string
     */
    protected $boundary = 'Boundary';

    /**
     * @param string $documentRoot
     * @param string $includePath
     */
    public function __construct($documentRoot = '/', $includePath = '.:/usr/share/pear:/usr/share/php')
    {
        $this->documentRoot = $documentRoot;
        $this->includePath = $includePath;
    }

    /**
     * @return string
     */
    public function getIncludePath()
    {
        return $this->includePath;
    }

    /**
     * @return string
     */
    public function getDocumentRoot()
    {
        return $this->documentRoot;
    }

    /**
     * @param string $includePath
     */
    public function setIncludePath($includePath)
    {
        $this->includePath = $includePath;
    }

    /**
     * @param string $documentRoot
     */
    public function setDocumentRoot($documentRoot)
    {
        $this->documentRoot = $documentRoot;
    }

    /**
     * @param string $path
     */
    public function addIncludePath($path)
    {
        $this->includePath .= $path;
    }

    /**
     * @param array $options
     */
    public function setPhpOptions($options)
    {
        $this->phpOptions = $options;
    }

    /**
     * @return array
     */
    public function getPhpOptions()
    {
        return $this->phpOptions;
    }

    /**
     * @param string $method
     * @param string $scriptFile
     * @param null|array $parameters
     * @param null|array $options
     * @param null $files
     * @return bool|Response
     */
    public function request($method, $scriptFile, $parameters = null, $options = null, $files = null)
    {
        $defaultOptions = [
            'SCRIPT_FILENAME' => $this->documentRoot . $scriptFile,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'REQUEST_METHOD' => $method,
            'REDIRECT_STATUS' => 'CGI',
        ];

        if ($files) {
            $reqBody = $this->generateStringForMultiPart($parameters, $files);
            $defaultOptions['CONTENT_TYPE'] = 'multipart/form-data; boundary=' . $this->boundary;
        } else {
            $reqBody = ($parameters) ? http_build_query($parameters) : "";
        }

        $defaultOptions['CONTENT_LENGTH'] = strlen($reqBody);

        $this->setEnv($defaultOptions);
        if ($options) {
            $this->setEnv($options);
        }

        $envStr = $this->makeEnvString();
        $phpOptionsStr = $this->makePhpOptionsString();
        $response = $this->send($reqBody, $envStr, $phpOptionsStr);
        $adjustedResponse = $this->setHttpProtocolToResponse($response);

        return $this->parseResponse($adjustedResponse);
    }

    /**
     * @param string $reqBody
     * @param string $envStr
     * @return string
     */
    public function send($reqBody, $envStr, $phpOptionsStr)
    {
        $tmpFileName = tempnam('/tmp', 'prefix');
        file_put_contents($tmpFileName, $reqBody);
        $result =  shell_exec("cat $tmpFileName | env $envStr php-cgi -d include_path=$this->includePath $phpOptionsStr");
        unlink($tmpFileName);

        return $result;
    }

    /**
     * @param string $scriptFile
     * @param null|array $parameters
     * @param null|array $options
     * @return bool|Response
     */
    public function get($scriptFile, $parameters = null, $options = null)
    {
        if ($parameters) {
            $this->env['QUERY_STRING'] = http_build_query($parameters);
        }

        return $this->request('GET', $scriptFile, $parameters, $options);
    }

    /**
     * @param string $scriptFile
     * @param null|array $parameters
     * @param null|array $options
     * @param null|array $files
     * @return bool|Response
     */
    public function post($scriptFile, $parameters = null, $options = null, $files= null)
    {
        return $this->request('POST', $scriptFile, $parameters, $options, $files);
    }

    /**
     * @return string
     */
    public function makeEnvString()
    {
        $array = [];
        foreach ($this->env as $key => $value) {
            array_push($array, "$key='$value'");
        }

        return implode(' ', $array);
    }

    /**
     * @param array $options
     */
    public function setEnv(array $options)
    {
        foreach ($options as $key => $value) {
            $this->env[$key] = $value;
        }
    }

    /**
     * @param null|array $optionNames
     * @return array
     */
    public function getEnv($optionNames = null)
    {
        $env = [];
        if ($optionNames) {
            foreach ($optionNames as $name) {
                $env[$name] = $this->env[$name];
            }
        } else {
            $env = $this->env;
        }

        return $env;
    }

    /**
     * @param string $response
     * @return string
     */
    public function setHttpProtocolToResponse($response)
    {
        $lines = preg_split('/(\\r?\\n)/', $response, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = explode(':', $lines[0], 2);
        $startLine = $parts[0] == 'Status' ? "HTTP/1.1" . $parts[1] . "\r\n" : "HTTP/1.1 200 OK";

        return $startLine . $response;
    }

    /**
     * @param string $response
     * @return bool|Response
     */
    public function parseResponse($response)
    {
        return Response::fromMessage($response);
    }

    /**
     * @param array $parameters
     * @param string $name
     */
    public function setSession(array $parameters, $name = 'PHPSESSID')
    {
        session_name($name);
        session_start();

        foreach ($parameters as $key => $value) {
            $_SESSION[$key] = $value;
        }

        if (isset($this->env['HTTP_COOKIE'])) {
            session_regenerate_id();
            $this->env['HTTP_COOKIE'] .= ";$name=" . session_id();
        } else {
            $this->env['HTTP_COOKIE'] = "$name=" . session_id();
        }
        session_write_close();
    }

    /**
     * @param string $name
     */
    public function initializeSession($name = 'PHPSESSID')
    {
        session_name($name);
        session_start();
        session_destroy();
    }

    /**
     * @return string
     */
    public function makePhpOptionsString()
    {
        $array = [];
        foreach ($this->phpOptions as $key => $value) {
            array_push($array, "-d $key='$value'");
        }

        return implode(' ', $array);
    }

    /**
     * @param null|array $parameters
     * @param null|array $files
     * @return string
     */
    public function generateStringForMultiPart($parameters=null, $files=null)
    {
        $string = '';

        if ($parameters) {
            foreach ($parameters as $key => $value) {
                $string .= <<<EOI
--$this->boundary
Content-Disposition: form-data; name="$key"

$value

EOI;
            }
        }

        if ($files) {
            foreach ($files as $file) {
                $name = $file['name'];
                $filename = $file['filename'];
                $contents = $file['contents'];
                $type     = $file['type'];

                $string .= <<<EOI
--$this->boundary
Content-Disposition: form-data; name="$name"; filename="$filename"
Content-Type: $type

$contents

EOI;
            }
        }

        if ($string != '') {
            $string .="--$this->boundary--";
        }

        return $string;
    }
}
