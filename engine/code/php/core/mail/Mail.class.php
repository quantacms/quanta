<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */

class Mail extends Node {
  /**
   * Send an email using phpmailer.
   * @throws phpmailerException
   */
  public function send() {

    require_once('mailer/PHPMailerAutoload.php');
    // $mail->SMTPDebug  = 2;
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = $this->getData('host');
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls';
    $mail->SMTPDebug = 0;
    $mail->Username = $this->getData('username');
    $mail->Password = $this->getData('password');
    $mail->Port = $this->getData('port');
    $mail->setFrom($this->getData('from'), $this->getData('from_name'));
    $mail->addAddress($this->getData('to'), $this->getData('to_name'));
    $mail->isHTML(true);
    // $mail->Subject = $this->getTitle();
    $mail->Subject = $this->getData('subject');
    $mail->Body    = $this->getBody();
    $mail->AltBody = $this->getBody();

    if(!$mail->send()) {
      new Message($this->env, 'Mailer Error: ' . $mail->ErrorInfo, MESSAGE_ERROR);
    } else {
      $this->delete();
    }
  }
} 
