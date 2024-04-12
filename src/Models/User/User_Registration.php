<?php
namespace Boostack\Models\User;
use Boostack\Models\Database\Database_PDO;
/**
 * Boostack: User_Registration.Class.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class User_Registration extends \Boostack\Models\BaseClass
{
    
    protected $activation_date;
    
    protected $access_code;
    
    protected $ip;
    
    protected $join_date;
    
    protected $join_idconfirm;

    /**
     *
     */
    const TABLENAME = "boostack_user_registration";

    /**
     * @var array
     */
    protected $default_values = [
        "activation_date" => 0,
        "access_code" => "",
        "ip" => "",
        "join_date" => 0,
        "join_idconfirm" => "",
    ];

    /**
     * User_Registration constructor.
     * @param null $id
     */
    public function __construct($id = null)
    {
        parent::init($id);
    }

    /**
     * Retrieves the user ID associated with a given join ID confirmation token.
     *
     * @param string $join_idconfirm The join ID confirmation token.
     * @param bool $throwException Whether to throw an \Exception if the token is not found (default: true).
     * @return int|false The user ID if the token is found, false otherwise.
     * @throws \Exception If the token is not found and $throwException is true.
     */
    public static function getUserIDJoinIdConfirm($join_idconfirm, $throwException = true)
    {
        $PDO = Database_PDO::getInstance();
        $query = "SELECT id FROM " . self::TABLENAME . " WHERE join_idconfirm = :join_idconfirm ";
        $q = $PDO->prepare($query);
        $q->bindParam(":join_idconfirm", $join_idconfirm);
        $q->execute();
        if ($q->rowCount() == 0) {
            if ($throwException) {
                throw new \Exception("Attention! Confirm token not found.", 0);
            }
            return false;
        }
        $res = $q->fetchAll(\PDO::FETCH_ASSOC);
        return (int)$res[0]["id"];
    }
}
