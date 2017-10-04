<?php

/**
 * Class Divante_Bus_Model_Api2
 */
class Divante_Bus_Model_Api2 extends Mage_Api2_Model_Resource
{
    /**
     * @param array $data
     * @param int $httpCode
     */
    protected function jsonResponse (array $data = [], $httpCode = 200)
    {
        header("Content-Type: application/json;charset=utf-8", true, $httpCode);
        echo json_encode($data);
        exit;
    }
}