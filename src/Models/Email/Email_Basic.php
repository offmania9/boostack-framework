<?php

namespace Boostack\Models\Email;

use function PHPSTORM_META\type;

/**
 * Boostack: Email_Basic.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

/**
 * Class Email_Basic
 * 
 * Represents a basic email message with support for attachments.
 */
class Email_Basic
{
    /** @var string The sender's email address. */
    private $from_mail;

    /** @var string The sender's name. */
    private $from_name;

    /** @var string The reply-to email address. */
    private $reply_to_mail;

    /** @var string The reply-to name. */
    private $reply_to_name;

    /** @var string The email subject. */
    private $subject;

    /** @var array The CC recipients. */
    private $cc = array();

    /** @var array The BCC recipients. */
    private $bcc = array();

    /** @var string The email headers. */
    private $headers;

    /** @var array The attachments. */
    private $attachment = array();

    /** @var string The message body. */
    private $message;

    /** @var string The clean message body without MIME formatting. */
    private $message_clean;

    /** @var string The date when the email was sent. */
    private $date_send;

    /** @var array The list of recipients. */
    private $to_list = array();

    /** @var string The MIME boundary for separating message parts. */
    private $mime_boundary;

    /**
     * Email_Basic constructor.
     *
     * @param array $options An array containing email parameters.
     * @throws \Exception If required parameters are missing.
     */
    public function __construct($options)
    {
        if (empty($options["from_mail"])) throw new \Exception("Missing 'from_mail' parameter");
        if (empty($options["message"])) throw new \Exception("Missing 'message' parameter");
        if (empty($options["to"])) throw new \Exception("Missing 'to' parameter");

        $this->from_mail = $options["from_mail"];
        $this->from_name = !empty($options["from_name"]) ? $options["from_name"] : "";
        $this->reply_to_mail = !empty($options["reply_mail"]) ? $options["reply_mail"] : "";
        $this->reply_to_name = !empty($options["reply_name"]) ? $options["reply_name"] : "";
        $this->subject = !empty($options["subject"]) ? $options["subject"] : "";
        $cc = !empty($options["cc"]) ? $options["cc"] : NULL;
        $bcc = !empty($options["bcc"]) ? $options["bcc"] : NULL;
        $this->message_clean = $options["message"];

        if ($options["to"] !== NULL) {
            if (is_array($options["to"]))
                foreach ($options["to"] as $v)
                    $this->to_list[] = $v;
            else
                $this->to_list[] = $options["to"];
        }
        if ($cc !== NULL) {
            if (is_array($cc))
                foreach ($cc as $v)
                    $this->cc[] = $v;
            else
                $this->cc[] = $cc;
        }
        if ($bcc !== NULL) {
            if (is_array($bcc))
                foreach ($bcc as $v)
                    $this->bcc[] = $v;
            else
                $this->bcc[] = $bcc;
        }

        $semi_rand = md5(time());
        $this->mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";

        $this->headers = "From: " . $this->from_mail;
        $this->headers .= "\nMIME-Version: 1.0\n" .
            "Content-Type: multipart/mixed; boundary=\"" . $this->mime_boundary . "\"";

        $this->message = "This is a multi-part alert in MIME format.\n\n" .
            "--" . $this->mime_boundary . "\n" .
            "Content-Type:text/html; charset=\"UTF-8\"\n" . //iso-8859-1
            "Content-Transfer-Encoding: 7bit\n\n" .
            $this->message_clean . "\n\n";
    }

    /**
     * Adds an email address to the recipient list.
     *
     * @param string $emailaddress The email address to add.
     */
    public function AddAddressToList($emailaddress)
    {
        $this->to_list[] = $emailaddress;
    }

    /**
     * Adds an attachment to the email.
     *
     * @param string $path The path to the attachment file.
     * @param string $type The MIME type of the attachment.
     */
    public function addAttachment($path, $mime_type)
    {
        $this->attachment[] = $path;
        $data = "";
        $file = fopen($path, 'rb');
        while (!feof($file)) {
            $data .= fread($file, 2048);
        }
        fclose($file);

        $this->addAttachmentFromBuffer($data, basename($path), $mime_type);
    }

    /**
     * Add an attachment to the email from a buffer.
     *
     * This method appends a new attachment to the email message using the provided
     * buffer data. It constructs the appropriate MIME headers for the attachment
     * and encodes the data in base64 format.
     *
     * @param string $buffer_data The binary data of the attachment.
     * @param string $name The name of the file being attached.
     * @param string $mime_type The MIME type of the attachment (e.g., 'image/jpeg').
     * @return void
     */
    public function addAttachmentFromBuffer($buffer_data, $name, $mime_type)
    {
        $encoded_data = chunk_split(base64_encode($buffer_data));

        $this->message .= "--" . $this->mime_boundary . "\r\n" .
            "Content-Type: " . $mime_type . "; name=\"" . $name . "\"\r\n" .
            "Content-Disposition: attachment; filename=\"" . $name . "\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            $encoded_data . "\r\n";
    }


    /**
     * Sends the email.
     *
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function Send()
    {
        $this->message .= "--" . $this->mime_boundary . "--\n";
        //$this->message = wordwrap($this->message, 70, "\r\n");
        foreach ($this->to_list as $value) {
            $ok = mail($value, $this->subject, $this->message, $this->headers);
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * Magic method to retrieve property values.
     *
     * @param string $property_name The name of the property.
     * @return mixed|null The value of the property if it exists, otherwise null.
     */
    public function __get($property_name)
    {
        if (isset($this->$property_name)) {
            return ($this->$property_name);
        } else {
            return (NULL);
        }
    }

    /**
     * Magic method to set property values.
     *
     * @param string $property_name The name of the property.
     * @param mixed $val The value to set.
     */
    public function __set($property_name, $val)
    {
        $this->$property_name = $val;
    }
}
