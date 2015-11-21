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
    require_once('PHPMailer/PHPMailerAutoload.php');
    // $mail->SMTPDebug  = 2;
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls';
    $mail->Username = 'aldo.tripiciano@gmail.com';
    $mail->Password = 'blacklore3X';
    $mail->Port = 587;
    $mail->setFrom('aldo.tripiciano@gmail.com', 'Mailer');
    $mail->addAddress('bladesty@yahoo.it', 'Joe User');
    $mail->isHTML(true);
    $mail->Subject = $this->getTitle();
    $mail->Body    = $this->getContent();
    $mail->AltBody = $this->getContent();
    if(!$mail->send()) {
      echo 'Message could not be sent.';
      echo 'Mailer Error: ' . $mail->ErrorInfo;
    } else {
      echo 'Message has been sent';
      $this->delete();
    }
  }
} 