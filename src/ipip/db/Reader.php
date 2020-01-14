<?php

namespace ipip\db;

class Reader
{
    const IPV4 = 1;
    const IPV6 = 2;

    private $fileSize = 0;
    private $nodeCount = 0;
    private $nodeOffset = 0;

    private $meta = [];

    private $database = '';
    private $database_content = null;
    private static $database_content_cache = null;

    /**
     * Reader constructor.
     * @param $database
     * @throws \Exception
     */
    public function __construct($database)
    {
        $this->database = $database;

        $this->init();
    }

    private function init()
    {
        if (is_readable($this->database) === FALSE) {
            throw new \InvalidArgumentException("The IP Database file \"{$this->database}\" does not exist or is not readable.");
        }
        if(empty(self::$database_content_cache)) {
            self::$database_content_cache = file_get_contents($this->database);
        }
        $this->database_content = self::$database_content_cache;
        if (empty($this->database_content)) {
            throw new \InvalidArgumentException("IP Database File opening \"{$this->database}\".");
        }
        $this->fileSize = strlen($this->database_content);
        if ($this->fileSize === FALSE) {
            throw new \UnexpectedValueException("Error determining the size of \"{$this->database}\".");
        }

        $metaLength = unpack('N', substr($this->database_content, 0, 4))[1];
        $text = substr($this->database_content, 4, $metaLength);

        $this->meta = json_decode($text, 1);

        if (!isset($this->meta['fields']) || !isset($this->meta['languages'])) {
            throw new \Exception('IP Database metadata error.');
        }

        $fileSize = 4 + $metaLength + $this->meta['total_size'];
        if ($fileSize != $this->fileSize) {
            throw  new \Exception('IP Database size error.');
        }

        $this->nodeCount = $this->meta['node_count'];
        $this->nodeOffset = 4 + $metaLength;
    }

    /**
     * @param $ip
     * @param string $language
     * @return array|NULL
     */
    public function find($ip, $language = 'CN')
    {
        if (!isset($this->meta['languages'][$language])) {
            throw new \InvalidArgumentException("language : {$language} not support");
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === FALSE) {
            throw new \InvalidArgumentException("The value \"$ip\" is not a valid IP address.");
        }

        if (strpos($ip, '.') !== FALSE && !$this->supportV4()) {
            throw new \InvalidArgumentException("The Database not support IPv4 address.");
        } elseif (strpos($ip, ':') !== FALSE && !$this->supportV6()) {
            throw new \InvalidArgumentException("The Database not support IPv6 address.");
        }

        try {
            $node = $this->findNode($ip);
            if ($node > 0) {
                $data = $this->resolve($node);

                $values = explode("\t", $data);

                return array_slice($values, $this->meta['languages'][$language], count($this->meta['fields']));
            }
        } catch (\Exception $e) {
            return NULL;
        }

        return NULL;
    }

    public function findMap($ip, $language = 'CN')
    {
        $array = $this->find($ip, $language);
        if (NULL == $array) {
            return NULL;
        }

        return array_combine($this->meta['fields'], $array);
    }

    /**
     * @param $ip
     * @return int
     * @throws \Exception
     */
    private function findNode($ip)
    {
        static $v4offset = 0;
        static $v6offsetCache = [];

        $binary = inet_pton($ip);
        $bitCount = strlen($binary) * 8; // 32 | 128
        $key = substr($binary, 0, 2);
        $node = 0;
        $index = 0;
        if ($bitCount === 32) {
            if ($v4offset === 0) {
                for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
                    if ($i >= 80) {
                        $idx = 1;
                    } else {
                        $idx = 0;
                    }
                    $node = $this->readNode($node, $idx);
                    if ($node > $this->nodeCount) {
                        return 0;
                    }
                }
                $v4offset = $node;
            } else {
                $node = $v4offset;
            }
        } else {
            if (isset($v6offsetCache[$key])) {
                $index = 16;
                $node = $v6offsetCache[$key];
            }
        }

//        $this->buildReadNodeCache($v4offset);
//        exit();

        for ($i = $index; $i < $bitCount; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }

            $node = $this->readNode($node, 1 & ((0xFF & ord($binary[$i >> 3])) >> 7 - ($i % 8)));

            if ($i == 15) {
                $v6offsetCache[$key] = $node;
            }
        }

        if ($node === $this->nodeCount) {
            return 0;
        } elseif ($node > $this->nodeCount) {
            return $node;
        }

        throw new \Exception("find node failed");
    }

    /**
     * @param $node
     * @param $index
     * @return mixed
     * @throws \Exception
     */
    private function readNode($node, $index)
    {
        if ($this->fileSize === 3330475) {
            // 2019 年 1 月发布的二十二个公开版本
            static $node_caches = [
                96 => [97, 193286],
                97 => [98, 73893],
                98 => [99, 18717],
                99 => [100, 8194],
                100 => [101, 5465],
                8194 => [8195, 15736],
                18717 => [18718, 43791],
                18718 => [18719, 30230],
                43791 => [43792, 47736],
                73893 => [73894, 121507],
                73894 => [73895, 93372],
                73895 => [73896, 86634],
                93372 => [93373, 104657],
                121507 => [121508, 156117],
                121508 => [121509, 138207],
                156117 => [156118, 175673],
                193286 => [193287, 272840],
                193287 => [193288, 220581],
                193288 => [193289, 205514],
                193289 => [193290, 199016],
                205514 => [205515, 212285],
                220581 => [220582, 236444],
                220582 => [220583, 227012],
                236444 => [236445, 248793],
                272840 => [272841, 411411],
                272841 => [272842, 347467],
                272842 => [272843, 317811],
                347467 => [347468, 372122],
                411411 => [411452, 411412],
                411412 => [411452, 411413],
            ];
            if (isset($node_caches[$node])) return $node_caches[$node][$index];
        }
        return unpack('N', $this->read($node * 8 + $index * 4, 4))[1];
    }

    /**
     * @param $node
     * @return mixed
     * @throws \Exception
     */
    private function resolve($node)
    {
        $resolved = $node - $this->nodeCount + $this->nodeCount * 8;
        if ($resolved >= $this->fileSize) {
            return NULL;
        }

        $bytes = $this->read($resolved, 2);
        $size = unpack('N', str_pad($bytes, 4, "\x00", STR_PAD_LEFT))[1];

        $resolved += 2;

        return $this->read($resolved, $size);
    }

    public function close()
    {
    }

    /**
     * @param $offset
     * @param $length
     * @return bool|string
     * @throws \Exception
     */
    private function read($offset, $length)
    {
        return substr($this->database_content, $offset + $this->nodeOffset, $length);
    }

    public function supportV6()
    {
        return ($this->meta['ip_version'] & self::IPV6) === self::IPV6;
    }

    public function supportV4()
    {
        return ($this->meta['ip_version'] & self::IPV4) === self::IPV4;
    }

    public function getMeta()
    {
        return $this->meta;
    }

    /**
     * @return int  UTC Timestamp
     */
    public function getBuildTime()
    {
        return $this->meta['build'];
    }


    private function buildReadNodeCache($node, $depth = 0)
    {
        if ($depth > 4) return;
        $node_0 = $this->readNode($node, 0);
        $node_1 = $this->readNode($node, 1);
        echo $node, ' => [', $node_0, ', ', $node_1, '],', "\n";
        if ($node_0 < $this->nodeCount) {
            $this->buildReadNodeCache($node_0, $depth + 1);
        }
        if ($node_1 < $this->nodeCount) {
            $this->buildReadNodeCache($node_1, $depth + 1);
        }
    }
}
