<?php

class ChangeMailer {
    /**
     * @var array Mail configuration.
     */
    private $mail_config;

    private $HTML_MAIL_HEADER = <<<'EOD'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width"/>
	<style>
EOD;

    private $HTML_MAIL_FOOTER = <<<'EOD'
</body>
</html>
EOD;



    /**
     * @var ChangeLogger This is where we get the diff data from.
     */
    private $change_logger;

    function __construct($mail_config, $change_logger, $params) {
        $this->change_logger = $change_logger;
        $this->mail_config = $mail_config;
        $this->dry_run = isset($params['dry-run']) ? $params['dry-run'] : null;
    }

    /**
     * @return bool Sends a HTML diff e-mail.
     */
    public function send() {

        if($this->dry_run) { print($this->build_message()); }

        return mail(
            $this->mail_config['to'],
            $this->mail_config['subject'],
            $this->build_message(),
            $this->build_header($this->mail_config['headers']));
    }

    /**
     * @return string Creates the email message, wrapping the diff in before and after content,
     * separated by HTML newlines.
     */
    private function build_message()
    {
        return
            $this->HTML_MAIL_HEADER .
            "<style>" .
            $this->build_style() .
            "</style>" .
            $this->mail_config['before_diff'] .
            "<br />" .
            "<br />" .
            $this->change_logger->html_diff() .
            "<br />" .
            "<br />" .
            $this->mail_config['after_diff'] .
            $this->HTML_MAIL_FOOTER;
    }

    /**
     * @param array $raw_headers An associative array containing key-value pairs used as headers.
     * @return string A properly formatted email header with correct \r\n usage.
     */
    private function build_header($raw_headers) {
        $headers = array();
        foreach ($raw_headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }

        return implode("\r\n", $headers);
    }

    private function build_style() {
        global $iface_style;
        return file_get_contents("./style/" . $iface_style . ".css");
    }
}
