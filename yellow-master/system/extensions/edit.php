<?php
// Edit extension, https://github.com/datenstrom/yellow-extensions/tree/master/features/edit
// Copyright (c) 2013-2019 Datenstrom, https://datenstrom.se
// This file may be used and distributed under the terms of the public license.

class YellowEdit {
    const VERSION = "0.8.7";
    const TYPE = "feature";
    public $yellow;         //access to API
    public $response;       //web response
    public $users;          //user accounts
    public $merge;          //text merge

    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->response = new YellowEditResponse($yellow);
        $this->users = new YellowEditUsers($yellow);
        $this->merge = new YellowEditMerge($yellow);
        $this->yellow->system->setDefault("editLocation", "/edit/");
        $this->yellow->system->setDefault("editUploadNewLocation", "/media/@group/@filename");
        $this->yellow->system->setDefault("editUploadExtensions", ".gif, .jpg, .pdf, .png, .svg, .tgz, .zip");
        $this->yellow->system->setDefault("editKeyboardShortcuts", "ctrl+b bold, ctrl+i italic, ctrl+k strikethrough, ctrl+e code, ctrl+s save, ctrl+alt+p preview");
        $this->yellow->system->setDefault("editToolbarButtons", "auto");
        $this->yellow->system->setDefault("editEndOfLine", "auto");
        $this->yellow->system->setDefault("editNewFile", "page-new-(.*).md");
        $this->yellow->system->setDefault("editUserFile", "user.ini");
        $this->yellow->system->setDefault("editUserPasswordMinLength", "8");
        $this->yellow->system->setDefault("editUserHashAlgorithm", "bcrypt");
        $this->yellow->system->setDefault("editUserHashCost", "10");
        $this->yellow->system->setDefault("editUserHome", "/");
        $this->yellow->system->setDefault("editLoginRestriction", "0");
        $this->yellow->system->setDefault("editLoginSessionTimeout", "2592000");
        $this->yellow->system->setDefault("editBruteForceProtection", "25");
        $this->users->load($this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile"));
    }
    
    // Handle request
    public function onRequest($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if ($this->checkRequest($location)) {
            $scheme = $this->yellow->system->get("serverScheme");
            $address = $this->yellow->system->get("serverAddress");
            $base = rtrim($this->yellow->system->get("serverBase").$this->yellow->system->get("editLocation"), "/");
            list($scheme, $address, $base, $location, $fileName) = $this->yellow->getRequestInformation($scheme, $address, $base);
            $this->yellow->page->setRequestInformation($scheme, $address, $base, $location, $fileName);
            $statusCode = $this->processRequest($scheme, $address, $base, $location, $fileName);
        }
        return $statusCode;
    }
    
    // Handle page meta data
    public function onParseMeta($page) {
        if ($page==$this->yellow->page && $this->response->isActive()) {
            if ($this->response->isUser()) {
                if (empty($this->response->rawDataSource)) $this->response->rawDataSource = $page->rawData;
                if (empty($this->response->rawDataEdit)) $this->response->rawDataEdit = $page->rawData;
                if (empty($this->response->rawDataEndOfLine)) $this->response->rawDataEndOfLine = $this->response->getEndOfLine($page->rawData);
                if ($page->statusCode==434) $this->response->rawDataEdit = $this->response->getRawDataNew($page, true);
            }
            if (empty($this->response->language)) $this->response->language = $page->get("language");
            if (empty($this->response->action)) $this->response->action = $this->response->isUser() ? "none" : "login";
            if (empty($this->response->status)) $this->response->status = "none";
            if ($this->response->status=="error") $this->response->action = "error";
        }
    }
    
    // Handle page content of shortcut
    public function onParseContentShortcut($page, $name, $text, $type) {
        $output = null;
        if ($name=="edit" && $type=="inline") {
            $editText = "$name $text";
            if (substru($text, 0, 2)=="- ") $editText = trim(substru($text, 2));
            $output = "<a href=\"".$page->get("pageEdit")."\">".htmlspecialchars($editText)."</a>";
        }
        return $output;
    }
    
    // Handle page extra data
    public function onParsePageExtra($page, $name) {
        $output = null;
        if ($name=="header" && $this->response->isActive()) {
            $extensionLocation = $this->yellow->system->get("serverBase").$this->yellow->system->get("extensionLocation");
            $output = "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" data-bundle=\"none\" href=\"{$extensionLocation}edit.css\" />\n";
            $output .= "<script type=\"text/javascript\" data-bundle=\"none\" src=\"{$extensionLocation}edit.js\"></script>\n";
            $output .= "<script type=\"text/javascript\">\n";
            $output .= "// <![CDATA[\n";
            $output .= "yellow.page = ".json_encode($this->response->getPageData($page)).";\n";
            $output .= "yellow.system = ".json_encode($this->response->getSystemData()).";\n";
            $output .= "yellow.text = ".json_encode($this->response->getTextData()).";\n";
            $output .= "// ]]>\n";
            $output .= "</script>\n";
        }
        return $output;
    }
    
    // Handle command
    public function onCommand($args) {
        list($command) = $args;
        switch ($command) {
            case "user":    $statusCode = $this->processCommandUser($args); break;
            default:        $statusCode = 0;
        }
        return $statusCode;
    }
    
    // Handle command help
    public function onCommandHelp() {
        return "user [option email password name]\n";
    }

    // Handle update
    public function onUpdate($action) {
        if ($action=="update") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $fileData = $this->yellow->toolbox->readFile($fileNameUser);
            foreach ($this->yellow->toolbox->getTextLines($fileData) as $line) {
                preg_match("/^\s*(.*?)\s*:\s*(.*?)\s*$/", $line, $matches);
                if (!empty($matches[1]) && !empty($matches[2]) && $matches[1][0]!="#") {
                    list($hash, $name, $language, $status, $stamp, $modified, $errors, $pending, $home) = explode(",", $matches[2]);
                    if ($status!="active" && $status!="inactive") {
                        unset($this->users->users[$matches[1]]);
                        continue;
                    }
                    $pending = "none";
                    $this->users->set($matches[1], $hash, $name, $language, $status, $stamp, $modified, $errors, $pending, $home);
                    $fileDataNew .= "$matches[1]: $hash,$name,$language,$status,$stamp,$modified,$errors,$pending,$home\n";
                } else {
                    $fileDataNew .= $line;
                }
            }
            if ($fileData!=$fileDataNew) $this->yellow->toolbox->createFile($fileNameUser, $fileDataNew);
        }
    }
    
    // Process command to update user account
    public function processCommandUser($args) {
        list($command, $option) = $args;
        switch ($option) {
            case "":        $statusCode = $this->userShow($args); break;
            case "add":     $statusCode = $this->userAdd($args); break;
            case "change":  $statusCode = $this->userChange($args); break;
            case "remove":  $statusCode = $this->userRemove($args); break;
            default:        $statusCode = 400; echo "Yellow $command: Invalid arguments\n";
        }
        return $statusCode;
    }
    
    // Show user accounts
    public function userShow($args) {
        list($command) = $args;
        foreach ($this->users->getData() as $line) {
            echo "$line\n";
        }
        if (!$this->users->getNumber()) echo "Yellow $command: No user accounts\n";
        return 200;
    }
    
    // Add user account
    public function userAdd($args) {
        $status = "ok";
        list($command, $option, $email, $password, $name) = $args;
        if (empty($email) || empty($password)) $status = $this->response->status = "incomplete";
        if (empty($name)) $name = $this->yellow->system->get("sitename");
        if ($status=="ok") $status = $this->getUserAccount($email, $password, "add");
        if ($status=="ok" && $this->users->isTaken($email)) $status = "taken";
        switch ($status) {
            case "incomplete":  echo "ERROR updating settings: Please enter email and password!\n"; break;
            case "invalid":     echo "ERROR updating settings: Please enter a valid email!\n"; break;
            case "taken":       echo "ERROR updating settings: Please enter a different email!\n"; break;
            case "weak":        echo "ERROR updating settings: Please enter a different password!\n"; break;
        }
        if ($status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $status = $this->users->save($fileNameUser, $email, $password, $name, "", "active") ? "ok" : "error";
            if ($status=="error") echo "ERROR updating settings: Can't write file '$fileNameUser'!\n";
            $this->yellow->log($status=="ok" ? "info" : "error", "Add user '".strtok($name, " ")."'");
        }
        if ($status=="ok") {
            $algorithm = $this->yellow->system->get("editUserHashAlgorithm");
            $status = substru($this->users->getHash($email), 0, 10)!="error-hash" ? "ok" : "error";
            if ($status=="error") echo "ERROR updating settings: Hash algorithm '$algorithm' not supported!\n";
        }
        $statusCode = $status=="ok" ? 200 : 500;
        echo "Yellow $command: User account ".($statusCode!=200 ? "not " : "")."added\n";
        return $statusCode;
    }
    
    // Change user account
    public function userChange($args) {
        $status = "ok";
        list($command, $option, $email, $password, $name) = $args;
        if (empty($email)) $status = $this->response->status = "invalid";
        if ($status=="ok") $status = $this->getUserAccount($email, $password, "change");
        if ($status=="ok" && !$this->users->isExisting($email)) $status = "unknown";
        switch ($status) {
            case "invalid": echo "ERROR updating settings: Please enter a valid email!\n"; break;
            case "unknown": echo "ERROR updating settings: Can't find email '$email'!\n"; break;
            case "weak":    echo "ERROR updating settings: Please enter a different password!\n"; break;
        }
        if ($status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $status = $this->users->save($fileNameUser, $email, $password, $name) ? "ok" : "error";
            if ($status=="error") echo "ERROR updating settings: Can't write file '$fileNameUser'!\n";
        }
        $statusCode = $status=="ok" ? 200 : 500;
        echo "Yellow $command: User account ".($statusCode!=200 ? "not " : "")."changed\n";
        return $statusCode;
    }

    // Remove user account
    public function userRemove($args) {
        $status = "ok";
        list($command, $option, $email) = $args;
        $name = $this->users->getName($email);
        if (empty($email)) $status = $this->response->status = "invalid";
        if (empty($name)) $name = $this->yellow->system->get("sitename");
        if ($status=="ok") $status = $this->getUserAccount($email, "", "remove");
        if ($status=="ok" && !$this->users->isExisting($email)) $status = "unknown";
        switch ($status) {
            case "invalid": echo "ERROR updating settings: Please enter a valid email!\n"; break;
            case "unknown": echo "ERROR updating settings: Can't find email '$email'!\n"; break;
        }
        if ($status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $status = $this->users->remove($fileNameUser, $email) ? "ok" : "error";
            if ($status=="error") echo "ERROR updating settings: Can't write file '$fileNameUser'!\n";
            $this->yellow->log($status=="ok" ? "info" : "error", "Remove user '".strtok($name, " ")."'");
        }
        $statusCode = $status=="ok" ? 200 : 500;
        echo "Yellow $command: User account ".($statusCode!=200 ? "not " : "")."removed\n";
        return $statusCode;
    }
    
    // Process request
    public function processRequest($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if ($this->checkUserAuth($scheme, $address, $base, $location, $fileName)) {
            switch ($_REQUEST["action"]) {
                case "":            $statusCode = $this->processRequestShow($scheme, $address, $base, $location, $fileName); break;
                case "login":       $statusCode = $this->processRequestLogin($scheme, $address, $base, $location, $fileName); break;
                case "logout":      $statusCode = $this->processRequestLogout($scheme, $address, $base, $location, $fileName); break;
                case "quit":        $statusCode = $this->processRequestQuit($scheme, $address, $base, $location, $fileName); break;
                case "account":     $statusCode = $this->processRequestAccount($scheme, $address, $base, $location, $fileName); break;
                case "about":       $statusCode = $this->processRequestAbout($scheme, $address, $base, $location, $fileName); break;
                case "update":      $statusCode = $this->processRequestUpdate($scheme, $address, $base, $location, $fileName); break;
                case "create":      $statusCode = $this->processRequestCreate($scheme, $address, $base, $location, $fileName); break;
                case "edit":        $statusCode = $this->processRequestEdit($scheme, $address, $base, $location, $fileName); break;
                case "delete":      $statusCode = $this->processRequestDelete($scheme, $address, $base, $location, $fileName); break;
                case "preview":     $statusCode = $this->processRequestPreview($scheme, $address, $base, $location, $fileName); break;
                case "upload":      $statusCode = $this->processRequestUpload($scheme, $address, $base, $location, $fileName); break;
            }
        } elseif ($this->checkUserUnauth($scheme, $address, $base, $location, $fileName)) {
            $this->yellow->lookup->requestHandler = "core";
            switch ($_REQUEST["action"]) {
                case "":            $statusCode = $this->processRequestShow($scheme, $address, $base, $location, $fileName); break;
                case "signup":      $statusCode = $this->processRequestSignup($scheme, $address, $base, $location, $fileName); break;
                case "forgot":      $statusCode = $this->processRequestForgot($scheme, $address, $base, $location, $fileName); break;
                case "confirm":     $statusCode = $this->processRequestConfirm($scheme, $address, $base, $location, $fileName); break;
                case "approve":     $statusCode = $this->processRequestApprove($scheme, $address, $base, $location, $fileName); break;
                case "recover":     $statusCode = $this->processRequestRecover($scheme, $address, $base, $location, $fileName); break;
                case "reactivate":  $statusCode = $this->processRequestReactivate($scheme, $address, $base, $location, $fileName); break;
                case "verify":      $statusCode = $this->processRequestVerify($scheme, $address, $base, $location, $fileName); break;
                case "change":      $statusCode = $this->processRequestChange($scheme, $address, $base, $location, $fileName); break;
                case "remove":      $statusCode = $this->processRequestRemove($scheme, $address, $base, $location, $fileName); break;
            }
        }
        if ($statusCode==0) $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        $this->checkUserFailed($scheme, $address, $base, $location, $fileName);
        return $statusCode;
    }
    
    // Process request to show file
    public function processRequestShow($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if (is_readable($fileName)) {
            $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        } else {
            if ($this->yellow->lookup->isRedirectLocation($location)) {
                $location = $this->yellow->lookup->isFileLocation($location) ? "$location/" : "/".$this->yellow->getRequestLanguage()."/";
                $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
                $statusCode = $this->yellow->sendStatus(301, $location);
            } else {
                $this->yellow->page->error($this->response->isUserRestriction() ? 404 : 434);
                $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
            }
        }
        return $statusCode;
    }

    // Process request for user login
    public function processRequestLogin($scheme, $address, $base, $location, $fileName) {
        $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
        if ($this->users->save($fileNameUser, $this->response->userEmail)) {
            $home = $this->users->getHome($this->response->userEmail);
            if (substru($location, 0, strlenu($home))==$home) {
                $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
                $statusCode = $this->yellow->sendStatus(303, $location);
            } else {
                $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $home);
                $statusCode = $this->yellow->sendStatus(302, $location);
            }
        } else {
            $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        }
        return $statusCode;
    }
    
    // Process request for user logout
    public function processRequestLogout($scheme, $address, $base, $location, $fileName) {
        $this->response->userEmail = "";
        $this->response->destroyCookies($scheme, $address, $base);
        $location = $this->yellow->lookup->normaliseUrl(
            $this->yellow->system->get("serverScheme"),
            $this->yellow->system->get("serverAddress"),
            $this->yellow->system->get("serverBase"),
            $location);
        $statusCode = $this->yellow->sendStatus(302, $location);
        return $statusCode;
    }

    // Process request for user signup
    public function processRequestSignup($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "signup";
        $this->response->status = "ok";
        $name = trim(preg_replace("/[^\pL\d\-\. ]/u", "-", $_REQUEST["name"]));
        $email = trim($_REQUEST["email"]);
        $password = trim($_REQUEST["password"]);
        $consent = trim($_REQUEST["consent"]);
        if (empty($name) || empty($email) || empty($password) || empty($consent)) $this->response->status = "incomplete";
        if ($this->response->status=="ok") $this->response->status = $this->getUserAccount($email, $password, $this->response->action);
        if ($this->response->status=="ok" && $this->response->isLoginRestriction()) $this->response->status = "next";
        if ($this->response->status=="ok" && $this->users->isTaken($email)) $this->response->status = "next";
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $language = $this->yellow->lookup->findLanguageFromFile($fileName, $this->yellow->system->get("language"));
            $this->response->status = $this->users->save($fileNameUser, $email, $password, $name, $language, "unconfirmed") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        if ($this->response->status=="ok") {
            $algorithm = $this->yellow->system->get("editUserHashAlgorithm");
            $this->response->status = substru($this->users->getHash($email), 0, 10)!="error-hash" ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Hash algorithm '$algorithm' not supported!");
        }
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, "confirm") ? "next" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to confirm user signup
    public function processRequestConfirm($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "confirm";
        $this->response->status = "ok";
        $email = $_REQUEST["email"];
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->save($fileNameUser, $email, "", "", "", "unapproved") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, "approve") ? "done" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to approve user signup
    public function processRequestApprove($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "approve";
        $this->response->status = "ok";
        $email = $_REQUEST["email"];
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->save($fileNameUser, $email, "", "", "", "active") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            $this->yellow->log($this->response->status=="ok" ? "info" : "error", "Add user '".strtok($this->users->getName($email), " ")."'");
        }
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, "welcome") ? "done" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }

    // Process request for forgotten password
    public function processRequestForgot($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "forgot";
        $this->response->status = "ok";
        $email = trim($_REQUEST["email"]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->response->status = "invalid";
        if ($this->response->status=="ok" && !$this->users->isExisting($email)) $this->response->status = "next";
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, "recover") ? "next" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to recover password
    public function processRequestRecover($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "recover";
        $this->response->status = "ok";
        $email = trim($_REQUEST["email"]);
        $password = trim($_REQUEST["password"]);
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            if (empty($password)) $this->response->status = "password";
            if ($this->response->status=="ok") $this->response->status = $this->getUserAccount($email, $password, $this->response->action);
            if ($this->response->status=="ok") {
                $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
                $this->response->status = $this->users->save($fileNameUser, $email, $password) ? "ok" : "error";
                if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            }
            if ($this->response->status=="ok") {
                $this->response->destroyCookies($scheme, $address, $base);
                $this->response->status = "done";
            }
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to reactivate account
    public function processRequestReactivate($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "reactivate";
        $this->response->status = "ok";
        $email = $_REQUEST["email"];
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->save($fileNameUser, $email, "", "", "", "active") ? "done" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to verify email
    public function processRequestVerify($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "verify";
        $this->response->status = "ok";
        $email = $emailSource = $_REQUEST["email"];
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            $emailSource = $this->users->getPending($email);
            if ($this->users->getStatus($emailSource)!="active") $this->response->status = "done";
        }
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->save($fileNameUser, $email, "", "", "", "unchanged") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $emailSource, "change") ? "done" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to change email or password
    public function processRequestChange($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "change";
        $this->response->status = "ok";
        $email = $emailSource = trim($_REQUEST["email"]);
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            list($email, $hash) = explode(":", $this->users->getPending($email), 2);
            if (!$this->users->isExisting($email) || empty($hash)) $this->response->status = "done";
        }
        if ($this->response->status=="ok") {
            $this->users->users[$email]["hash"] = $hash;
            $this->users->users[$email]["pending"] = "none";
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->save($fileNameUser, $email, "", "", "", "active") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        if ($this->response->status=="ok" && $email!=$emailSource) {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->remove($fileNameUser, $emailSource) ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        if ($this->response->status=="ok") {
            $this->response->destroyCookies($scheme, $address, $base);
            $this->response->status = "done";
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to quit account
    public function processRequestQuit($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "quit";
        $this->response->status = "ok";
        $name = trim($_REQUEST["name"]);
        $email = $this->response->userEmail;
        if (empty($name)) $this->response->status = "none";
        if ($this->response->status=="ok" && $name!=$this->users->getName($email)) $this->response->status = "mismatch";
        if ($this->response->status=="ok") $this->response->status = $this->getUserAccount($email, "", $this->response->action);
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, "remove") ? "next" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to remove account
    public function processRequestRemove($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "remove";
        $this->response->status = "ok";
        $email = $_REQUEST["email"];
        $this->response->status = $this->getUserStatus($email, $_REQUEST["action"]);
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->save($fileNameUser, $email, "", "", "", "removed") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            $this->yellow->log($this->response->status=="ok" ? "info" : "error", "Remove user '".strtok($this->users->getName($email), " ")."'");
        }
        if ($this->response->status=="ok") {
            $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, "goodbye") ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
        }
        if ($this->response->status=="ok") {
            $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
            $this->response->status = $this->users->remove($fileNameUser, $email) ? "ok" : "error";
            if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
        }
        if ($this->response->status=="ok") {
            $this->response->destroyCookies($scheme, $address, $base);
            $this->response->status = "done";
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to change account settings
    public function processRequestAccount($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "account";
        $this->response->status = "ok";
        $email = trim($_REQUEST["email"]);
        $emailSource = $this->response->userEmail;
        $password = trim($_REQUEST["password"]);
        $name = trim(preg_replace("/[^\pL\d\-\. ]/u", "-", $_REQUEST["name"]));
        $language = trim($_REQUEST["language"]);
        if ($email!=$emailSource || !empty($password)) {
            if (empty($email)) $this->response->status = "invalid";
            if ($this->response->status=="ok") $this->response->status = $this->getUserAccount($email, $password, $this->response->action);
            if ($this->response->status=="ok" && $email!=$emailSource && $this->users->isTaken($email)) $this->response->status = "taken";
            if ($this->response->status=="ok" && $email!=$emailSource) {
                $pending = $emailSource;
                $home = $this->users->getHome($emailSource);
                $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
                $this->response->status = $this->users->save($fileNameUser, $email, "no", $name, $language, "unverified", "", "", "", $pending, $home) ? "ok" : "error";
                if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            }
            if ($this->response->status=="ok") {
                $pending = $email.":".(empty($password) ? $this->users->getHash($emailSource) : $this->users->createHash($password));
                $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
                $this->response->status = $this->users->save($fileNameUser, $emailSource, "", $name, $language, "", "", "", "", $pending) ? "ok" : "error";
                if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            }
            if ($this->response->status=="ok") {
                $action = $email!=$emailSource ? "verify" : "change";
                $this->response->status = $this->response->sendMail($scheme, $address, $base, $email, $action) ? "next" : "error";
                if ($this->response->status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
            }
        } else {
            if ($this->response->status=="ok") {
                $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
                $this->response->status = $this->users->save($fileNameUser, $email, "", $name, $language) ? "done" : "error";
                if ($this->response->status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
            }
        }
        if ($this->response->status=="done") {
            $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
            $statusCode = $this->yellow->sendStatus(303, $location);
        } else {
            $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        }
        return $statusCode;
    }
    
    // Process request to show website version and updates
    public function processRequestAbout($scheme, $address, $base, $location, $fileName) {
        $this->response->action = "about";
        $this->response->status = "ok";
        if ($this->yellow->extensions->isExisting("update")) {
            list($statusCodeCurrent, $dataCurrent) = $this->yellow->extensions->get("update")->getExtensionsVersion();
            list($statusCodeLatest, $dataLatest) = $this->yellow->extensions->get("update")->getExtensionsVersion(true);
            list($statusCodeModified, $dataModified) = $this->yellow->extensions->get("update")->getExtensionsModified();
            $statusCode = max($statusCodeCurrent, $statusCodeLatest, $statusCodeModified);
            if ($this->response->isUserWebmaster()) {
                foreach ($dataCurrent as $key=>$value) {
                    if (strnatcasecmp($dataCurrent[$key], $dataLatest[$key])<0) {
                        ++$updates;
                        $rawData = htmlspecialchars(ucfirst($key)." $dataLatest[$key]")."<br />\n";
                        $this->response->rawDataOutput .= $rawData;
                    }
                }
                if ($updates==0) {
                    foreach ($dataCurrent as $key=>$value) {
                        if (!is_null($dataModified[$key]) && !is_null($dataLatest[$key])) {
                            $rawData = $this->yellow->text->getTextHtml("editAboutUpdateModified", $this->response->language)." - <a href=\"#\" data-action=\"update\" data-status=\"update\" data-args=\"".$this->yellow->toolbox->normaliseArgs("extension:$key/option:force")."\">".$this->yellow->text->getTextHtml("editAboutUpdateForce", $this->response->language)."</a><br />\n";
                            $rawData = preg_replace("/@extension/i", htmlspecialchars(ucfirst($key)." $dataLatest[$key]"), $rawData);
                            $this->response->rawDataOutput .= $rawData;
                        }
                    }
                }
                $this->response->status = $updates ? "updates" : "done";
            } else {
                foreach ($dataCurrent as $key=>$value) {
                    if (strnatcasecmp($dataCurrent[$key], $dataLatest[$key])<0) ++$updates;
                }
                $this->response->status = $updates ? "warning" : "done";
            }
            if ($statusCode!=200) $this->response->status = "error";
        }
        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
        return $statusCode;
    }
    
    // Process request to update website
    public function processRequestUpdate($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if ($this->yellow->extensions->isExisting("update") && $this->response->isUserWebmaster()) {
            $extension = trim($_REQUEST["extension"]);
            $option = trim($_REQUEST["option"]);
            $statusCode = $this->yellow->command("update", $extension, $option);
            if ($statusCode==200) {
                $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
                $statusCode = $this->yellow->sendStatus(303, $location);
            }
        }
        return $statusCode;
    }
    
    // Process request to create page
    public function processRequestCreate($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if (!$this->response->isUserRestriction() && !empty($_REQUEST["rawdataedit"])) {
            $this->response->rawDataSource = $_REQUEST["rawdatasource"];
            $this->response->rawDataEdit = $_REQUEST["rawdatasource"];
            $this->response->rawDataEndOfLine = $_REQUEST["rawdataendofline"];
            $rawData = $_REQUEST["rawdataedit"];
            $page = $this->response->getPageNew($scheme, $address, $base, $location, $fileName,
                $rawData, $this->response->getEndOfLine());
            if (!$page->isError()) {
                if ($this->yellow->toolbox->createFile($page->fileName, $page->rawData, true)) {
                    $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $page->location);
                    $statusCode = $this->yellow->sendStatus(303, $location);
                } else {
                    $this->yellow->page->error(500, "Can't write file '$page->fileName'!");
                    $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
                }
            } else {
                $this->yellow->page->error(500, $page->get("pageError"));
                $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
            }
        }
        return $statusCode;
    }
    
    // Process request to edit page
    public function processRequestEdit($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if (!$this->response->isUserRestriction() && !empty($_REQUEST["rawdataedit"])) {
            $this->response->rawDataSource = $_REQUEST["rawdatasource"];
            $this->response->rawDataEdit = $_REQUEST["rawdataedit"];
            $this->response->rawDataEndOfLine = $_REQUEST["rawdataendofline"];
            $rawDataFile = $this->yellow->toolbox->readFile($fileName);
            $page = $this->response->getPageEdit($scheme, $address, $base, $location, $fileName,
                $this->response->rawDataSource, $this->response->rawDataEdit, $rawDataFile, $this->response->rawDataEndOfLine);
            if (!$page->isError()) {
                if ($this->yellow->lookup->isFileLocation($location)) {
                    if ($this->yellow->toolbox->renameFile($fileName, $page->fileName, true) &&
                        $this->yellow->toolbox->createFile($page->fileName, $page->rawData)) {
                        $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $page->location);
                        $statusCode = $this->yellow->sendStatus(303, $location);
                    } else {
                        $this->yellow->page->error(500, "Can't write file '$page->fileName'!");
                        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
                    }
                } else {
                    if ($this->yellow->toolbox->renameDirectory(dirname($fileName), dirname($page->fileName), true) &&
                        $this->yellow->toolbox->createFile($page->fileName, $page->rawData)) {
                        $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $page->location);
                        $statusCode = $this->yellow->sendStatus(303, $location);
                    } else {
                        $this->yellow->page->error(500, "Can't write file '$page->fileName'!");
                        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
                    }
                }
            } else {
                $this->yellow->page->error(500, $page->get("pageError"));
                $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
            }
        }
        return $statusCode;
    }

    // Process request to delete page
    public function processRequestDelete($scheme, $address, $base, $location, $fileName) {
        $statusCode = 0;
        if (!$this->response->isUserRestriction() && is_file($fileName)) {
            $this->response->rawDataSource = $_REQUEST["rawdatasource"];
            $this->response->rawDataEdit = $_REQUEST["rawdatasource"];
            $this->response->rawDataEndOfLine = $_REQUEST["rawdataendofline"];
            $rawDataFile = $this->yellow->toolbox->readFile($fileName);
            $page = $this->response->getPageDelete($scheme, $address, $base, $location, $fileName,
                $rawDataFile, $this->response->rawDataEndOfLine);
            if (!$page->isError()) {
                if ($this->yellow->lookup->isFileLocation($location)) {
                    if ($this->yellow->toolbox->deleteFile($fileName, $this->yellow->system->get("trashDir"))) {
                        $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
                        $statusCode = $this->yellow->sendStatus(303, $location);
                    } else {
                        $this->yellow->page->error(500, "Can't delete file '$fileName'!");
                        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
                    }
                } else {
                    if ($this->yellow->toolbox->deleteDirectory(dirname($fileName), $this->yellow->system->get("trashDir"))) {
                        $location = $this->yellow->lookup->normaliseUrl($scheme, $address, $base, $location);
                        $statusCode = $this->yellow->sendStatus(303, $location);
                    } else {
                        $this->yellow->page->error(500, "Can't delete file '$fileName'!");
                        $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
                    }
                }
            } else {
                $this->yellow->page->error(500, $page->get("pageError"));
                $statusCode = $this->yellow->processRequest($scheme, $address, $base, $location, $fileName, false);
            }
        }
        return $statusCode;
    }

    // Process request to show preview
    public function processRequestPreview($scheme, $address, $base, $location, $fileName) {
        $page = $this->response->getPagePreview($scheme, $address, $base, $location, $fileName,
            $_REQUEST["rawdataedit"], $_REQUEST["rawdataendofline"]);
        $statusCode = $this->yellow->sendData(200, $page->outputData, "", false);
        if (defined("DEBUG") && DEBUG>=1) {
            $parser = $page->get("parser");
            echo "YellowEdit::processRequestPreview parser:$parser<br/>\n";
        }
        return $statusCode;
    }
    
    // Process request to upload file
    public function processRequestUpload($scheme, $address, $base, $location, $fileName) {
        $data = array();
        $fileNameTemp = $_FILES["file"]["tmp_name"];
        $fileNameShort = preg_replace("/[^\pL\d\-\.]/u", "-", basename($_FILES["file"]["name"]));
        $fileSizeMax = $this->yellow->toolbox->getNumberBytes(ini_get("upload_max_filesize"));
        $extension = strtoloweru(($pos = strrposu($fileNameShort, ".")) ? substru($fileNameShort, $pos) : "");
        $extensions = preg_split("/\s*,\s*/", $this->yellow->system->get("editUploadExtensions"));
        if (!$this->response->isUserRestriction() && is_uploaded_file($fileNameTemp) &&
           filesize($fileNameTemp)<=$fileSizeMax && in_array($extension, $extensions)) {
            $file = $this->response->getFileUpload($scheme, $address, $base, $location, $fileNameTemp, $fileNameShort);
            if (!$file->isError() && $this->yellow->toolbox->copyFile($fileNameTemp, $file->fileName, true)) {
                $data["location"] = $file->getLocation();
            } else {
                $data["error"] = "Can't write file '$file->fileName'!";
            }
        } else {
            $data["error"] = "Can't write file '$fileNameShort'!";
        }
        $statusCode = $this->yellow->sendData(is_null($data["error"]) ? 200 : 500, json_encode($data), "a.json", false);
        return $statusCode;
    }
    
    // Check request
    public function checkRequest($location) {
        $locationLength = strlenu($this->yellow->system->get("editLocation"));
        $this->response->active = substru($location, 0, $locationLength)==$this->yellow->system->get("editLocation");
        return $this->response->isActive();
    }
    
    // Check user authentication
    public function checkUserAuth($scheme, $address, $base, $location, $fileName) {
        if ($this->isRequestSameSite("POST", $scheme, $address) || $_REQUEST["action"]=="") {
            if ($_REQUEST["action"]=="login") {
                $email = $_REQUEST["email"];
                $password = $_REQUEST["password"];
                if ($this->users->checkAuthLogin($email, $password)) {
                    $this->response->createCookies($scheme, $address, $base, $email);
                    $this->response->userEmail = $email;
                    $this->response->userRestriction = $this->getUserRestriction($email, $location, $fileName);
                    $this->response->language = $this->getUserLanguage($email);
                } else {
                    $this->response->userFailedError = "login";
                    $this->response->userFailedEmail = $email;
                    $this->response->userFailedExpire = PHP_INT_MAX;
                }
            } elseif (isset($_COOKIE["authtoken"]) && isset($_COOKIE["csrftoken"])) {
                if ($this->users->checkAuthToken($_COOKIE["authtoken"], $_COOKIE["csrftoken"], $_POST["csrftoken"], $_REQUEST["action"]=="")) {
                    $this->response->userEmail = $email = $this->users->getAuthEmail($_COOKIE["authtoken"]);
                    $this->response->userRestriction = $this->getUserRestriction($email, $location, $fileName);
                    $this->response->language = $this->getUserLanguage($email);
                } else {
                    $this->response->userFailedError = "auth";
                    $this->response->userFailedEmail = $this->users->getAuthEmail($_COOKIE["authtoken"]);
                    $this->response->userFailedExpire = $this->users->getAuthExpire($_COOKIE["authtoken"]);
                }
            }
        }
        return $this->response->isUser();
    }

    // Check user without authentication
    public function checkUserUnauth($scheme, $address, $base, $location, $fileName) {
        $ok = false;
        if ($_REQUEST["action"]=="" || $_REQUEST["action"]=="signup" || $_REQUEST["action"]=="forgot") {
            $ok = true;
        } elseif (isset($_REQUEST["actiontoken"])) {
            if ($this->users->checkActionToken($_REQUEST["actiontoken"], $_REQUEST["email"], $_REQUEST["action"], $_REQUEST["expire"])) {
                $ok = true;
                $this->response->language = $this->getActionLanguage($_REQUEST["language"]);
            } else {
                $this->response->userFailedError = "action";
                $this->response->userFailedEmail = $_REQUEST["email"];
                $this->response->userFailedExpire = $_REQUEST["expire"];
            }
        }
        return $ok;
    }

    // Check user failed
    public function checkUserFailed($scheme, $address, $base, $location, $fileName) {
        if (!empty($this->response->userFailedError)) {
            if ($this->response->userFailedExpire>time() && $this->users->isExisting($this->response->userFailedEmail)) {
                $email = $this->response->userFailedEmail;
                $modified = $this->users->getModified($email);
                $errors = $this->users->getErrors($email)+1;
                $fileNameUser = $this->yellow->system->get("settingDir").$this->yellow->system->get("editUserFile");
                $status = $this->users->save($fileNameUser, $email, "", "", "", "", "", $modified, $errors) ? "ok" : "error";
                if ($status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
                if ($errors==$this->yellow->system->get("editBruteForceProtection")) {
                    $statusBeforeProtection = $this->users->getStatus($email);
                    $statusAfterProtection = ($statusBeforeProtection=="active" || $statusBeforeProtection=="inactive") ? "inactive" : "failed";
                    if ($status=="ok") {
                        $status = $this->users->save($fileNameUser, $email, "", "", "", $statusAfterProtection, "", $modified, $errors) ? "ok" : "error";
                        if ($status=="error") $this->yellow->page->error(500, "Can't write file '$fileNameUser'!");
                    }
                    if ($status=="ok" && $statusBeforeProtection=="active") {
                        $status = $this->response->sendMail($scheme, $address, $base, $email, "reactivate") ? "done" : "error";
                        if ($status=="error") $this->yellow->page->error(500, "Can't send email on this server!");
                    }
                }
            }
            if ($this->response->userFailedError=="login" || $this->response->userFailedError=="auth") {
                $this->response->destroyCookies($scheme, $address, $base);
                $this->response->status = "error";
                $this->yellow->page->error(430);
            } else {
                $this->response->status = "error";
                $this->yellow->page->error(500, "Link has expired!");
            }
        }
    }
    
    // Return user status changes
    public function getUserStatus($email, $action) {
        switch ($action) {
            case "confirm":     $statusExpected = "unconfirmed"; break;
            case "approve":     $statusExpected = "unapproved"; break;
            case "recover":     $statusExpected = "active"; break;
            case "reactivate":  $statusExpected = "inactive"; break;
            case "verify":      $statusExpected = "unverified"; break;
            case "change":      $statusExpected = "active"; break;
            case "remove":      $statusExpected = "active"; break;
        }
        return $this->users->getStatus($email)==$statusExpected ? "ok" : "done";
    }

    // Return user account changes
    public function getUserAccount($email, $password, $action) {
        $status = null;
        foreach ($this->yellow->extensions->extensions as $key=>$value) {
            if (method_exists($value["obj"], "onEditUserAccount")) {
                $status = $value["obj"]->onEditUserAccount($email, $password, $action, $this->users);
                if (!is_null($status)) break;
            }
        }
        if (is_null($status)) {
            $status = "ok";
            if (!empty($password) && strlenu($password)<$this->yellow->system->get("editUserPasswordMinLength")) $status = "weak";
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $status = "invalid";
        }
        return $status;
    }
    
    // Return user restriction
    public function getUserRestriction($email, $location, $fileName) {
        $userRestriction = null;
        foreach ($this->yellow->extensions->extensions as $key=>$value) {
            if (method_exists($value["obj"], "onEditUserRestriction")) {
                $userRestriction = $value["obj"]->onEditUserRestriction($email, $location, $fileName, $this->users);
                if (!is_null($userRestriction)) break;
            }
        }
        if (is_null($userRestriction)) {
            $userRestriction = substru($location, 0, strlenu($this->users->getHome($email)))!=$this->users->getHome($email);
            $userRestriction |= empty($fileName) || strlenu(dirname($fileName))>128 || strlenu(basename($fileName))>128;
        }
        return $userRestriction;
    }
    
    // Return user language
    public function getUserLanguage($email) {
        $language = $this->users->getLanguage($email);
        if (!$this->yellow->text->isLanguage($language)) $language = $this->yellow->system->get("language");
        return $language;
    }

    // Return action language
    public function getActionLanguage($language) {
        if (!$this->yellow->text->isLanguage($language)) $language = $this->yellow->system->get("language");
        return $language;
    }
    
    // Check if request came from same site
    public function isRequestSameSite($method, $scheme, $address) {
        if (preg_match("#^(\w+)://([^/]+)(.*)$#", $_SERVER["HTTP_REFERER"], $matches)) $origin = "$matches[1]://$matches[2]";
        if (isset($_SERVER["HTTP_ORIGIN"])) $origin = $_SERVER["HTTP_ORIGIN"];
        return $_SERVER["REQUEST_METHOD"]==$method && $origin=="$scheme://$address";
    }
}
    
class YellowEditResponse {
    public $yellow;             //access to API
    public $extension;          //access to extension
    public $active;             //location is active? (boolean)
    public $userEmail;          //user email
    public $userRestriction;    //user with restriction? (boolean)
    public $userFailedError;    //error of failed authentication
    public $userFailedEmail;    //email of failed authentication
    public $userFailedExpire;   //expiration time of failed authentication
    public $rawDataSource;      //raw data of page for comparison
    public $rawDataEdit;        //raw data of page for editing
    public $rawDataOutput;      //raw data of dynamic output
    public $rawDataEndOfLine;   //end of line format for raw data
    public $language;           //response language
    public $action;             //response action
    public $status;             //response status
    
    public function __construct($yellow) {
        $this->yellow = $yellow;
        $this->extension = $yellow->extensions->get("edit");
    }
    
    // Return new page
    public function getPageNew($scheme, $address, $base, $location, $fileName, $rawData, $endOfLine) {
        $page = new YellowPage($this->yellow);
        $page->setRequestInformation($scheme, $address, $base, $location, $fileName);
        $page->parseData($this->normaliseLines($rawData, $endOfLine), false, 0);
        $this->editContentFile($page, "create");
        if ($this->yellow->content->find($page->location)) {
            $page->location = $this->getPageNewLocation($page->rawData, $page->location, $page->get("pageNewLocation"));
            $page->fileName = $this->getPageNewFile($page->location, $page->fileName, $page->get("published"));
            while ($this->yellow->content->find($page->location) || empty($page->fileName)) {
                $rawData = $this->yellow->toolbox->setMetaData($page->rawData, "title", $this->getTitleNext($page->rawData));
                $page->rawData = $this->normaliseLines($rawData, $endOfLine);
                $page->location = $this->getPageNewLocation($page->rawData, $page->location, $page->get("pageNewLocation"));
                $page->fileName = $this->getPageNewFile($page->location, $page->fileName, $page->get("published"));
                if (++$pageCounter>999) break;
            }
            if ($this->yellow->content->find($page->location) || empty($page->fileName)) {
                $page->error(500, "Page '".$page->get("title")."' is not possible!");
            }
        } else {
            $page->fileName = $this->getPageNewFile($page->location);
        }
        if ($this->extension->getUserRestriction($this->userEmail, $page->location, $page->fileName)) {
            $page->error(500, "Page '".$page->get("title")."' is restricted!");
        }
        return $page;
    }
    
    // Return modified page
    public function getPageEdit($scheme, $address, $base, $location, $fileName, $rawDataSource, $rawDataEdit, $rawDataFile, $endOfLine) {
        $page = new YellowPage($this->yellow);
        $page->setRequestInformation($scheme, $address, $base, $location, $fileName);
        $rawData = $this->extension->merge->merge(
            $this->normaliseLines($rawDataSource, $endOfLine),
            $this->normaliseLines($rawDataEdit, $endOfLine),
            $this->normaliseLines($rawDataFile, $endOfLine));
        $page->parseData($this->normaliseLines($rawData, $endOfLine), false, 0);
        $pageSource = new YellowPage($this->yellow);
        $pageSource->setRequestInformation($scheme, $address, $base, $location, $fileName);
        $pageSource->parseData($this->normaliseLines($rawDataSource, $endOfLine), false, 0);
        $this->editContentFile($page, "edit");
        if ($this->isMetaModified($pageSource, $page) && $page->location!=$this->yellow->content->getHomeLocation($page->location)) {
            $page->location = $this->getPageNewLocation($page->rawData, $page->location, $page->get("pageNewLocation"), true);
            $page->fileName = $this->getPageNewFile($page->location, $page->fileName, $page->get("published"));
            if ($page->location!=$pageSource->location && ($this->yellow->content->find($page->location) || empty($page->fileName))) {
                $page->error(500, "Page '".$page->get("title")."' is not possible!");
            }
        }
        if (empty($page->rawData)) $page->error(500, "Page has been modified by someone else!");
        if ($this->extension->getUserRestriction($this->userEmail, $page->location, $page->fileName) ||
            $this->extension->getUserRestriction($this->userEmail, $pageSource->location, $pageSource->fileName)) {
            $page->error(500, "Page '".$page->get("title")."' is restricted!");
        }
        return $page;
    }
    
    // Return deleted page
    public function getPageDelete($scheme, $address, $base, $location, $fileName, $rawData, $endOfLine) {
        $page = new YellowPage($this->yellow);
        $page->setRequestInformation($scheme, $address, $base, $location, $fileName);
        $page->parseData($this->normaliseLines($rawData, $endOfLine), false, 0);
        $this->editContentFile($page, "delete");
        if ($this->extension->getUserRestriction($this->userEmail, $page->location, $page->fileName)) {
            $page->error(500, "Page '".$page->get("title")."' is restricted!");
        }
        return $page;
    }

    // Return preview page
    public function getPagePreview($scheme, $address, $base, $location, $fileName, $rawData, $endOfLine) {
        $page = new YellowPage($this->yellow);
        $page->setRequestInformation($scheme, $address, $base, $location, $fileName);
        $page->parseData($this->normaliseLines($rawData, $endOfLine), false, 200);
        $this->yellow->text->setLanguage($page->get("language"));
        $class = "page-preview layout-".$page->get("layout");
        $output = "<div class=\"".htmlspecialchars($class)."\"><div class=\"content\">";
        if ($this->yellow->system->get("editToolbarButtons")!="none") $output .= "<h1>".$page->getHtml("titleContent")."</h1>\n";
        $output .= $page->getContent();
        $output .= "</div></div>";
        $page->setOutput($output);
        return $page;
    }
    
    // Return uploaded file
    public function getFileUpload($scheme, $address, $base, $pageLocation, $fileNameTemp, $fileNameShort) {
        $file = new YellowPage($this->yellow);
        $file->setRequestInformation($scheme, $address, $base, "/".$fileNameTemp, $fileNameTemp);
        $file->parseData(null, false, 0);
        $file->set("fileNameShort", $fileNameShort);
        $this->editMediaFile($file, "upload");
        $file->location = $this->getFileNewLocation($fileNameShort, $pageLocation, $file->get("fileNewLocation"));
        $file->fileName = substru($file->location, 1);
        while (is_file($file->fileName)) {
            $fileNameShort = $this->getFileNext(basename($file->fileName));
            $file->location = $this->getFileNewLocation($fileNameShort, $pageLocation, $file->get("fileNewLocation"));
            $file->fileName = substru($file->location, 1);
            if (++$fileCounter>999) break;
        }
        if (is_file($file->fileName)) $file->error(500, "File '".$file->get("fileNameShort")."' is not possible!");
        return $file;
    }

    // Return page data including status information
    public function getPageData($page) {
        $data = array();
        if ($this->isUser()) {
            $data["title"] = $this->yellow->toolbox->getMetaData($this->rawDataEdit, "title");
            $data["rawDataSource"] = $this->rawDataSource;
            $data["rawDataEdit"] = $this->rawDataEdit;
            $data["rawDataNew"] = $this->getRawDataNew($page);
            $data["rawDataOutput"] = strval($this->rawDataOutput);
            $data["rawDataEndOfLine"] = $this->rawDataEndOfLine;
            $data["scheme"] = $this->yellow->page->scheme;
            $data["address"] = $this->yellow->page->address;
            $data["base"] = $this->yellow->page->base;
            $data["location"] = $this->yellow->page->location;
            $data["safeMode"] = $this->yellow->page->safeMode;
        }
        if ($this->action!="none") $data = array_merge($data, $this->getRequestData());
        $data["action"] = $this->action;
        $data["status"] = $this->status;
        $data["statusCode"] = $this->yellow->page->statusCode;
        return $data;
    }
    
    // Return system data including user information
    public function getSystemData() {
        $data = $this->yellow->system->getData("", "Location");
        if ($this->isUser()) {
            $data["userEmail"] = $this->userEmail;
            $data["userName"] = $this->extension->users->getName($this->userEmail);
            $data["userLanguage"] = $this->extension->users->getLanguage($this->userEmail);
            $data["userStatus"] = $this->extension->users->getStatus($this->userEmail);
            $data["userHome"] = $this->extension->users->getHome($this->userEmail);
            $data["userRestriction"] = intval($this->isUserRestriction());
            $data["userWebmaster"] = intval($this->isUserWebmaster());
            $data["serverScheme"] = $this->yellow->system->get("serverScheme");
            $data["serverAddress"] = $this->yellow->system->get("serverAddress");
            $data["serverBase"] = $this->yellow->system->get("serverBase");
            $data["serverFileSizeMax"] = $this->yellow->toolbox->getNumberBytes(ini_get("upload_max_filesize"));
            $data["serverVersion"] = "Datenstrom Yellow ".YellowCore::VERSION;
            $data["serverExtensions"] = array();
            foreach ($this->yellow->extensions->extensions as $key=>$value) {
                $data["serverExtensions"][$key] = $value["type"];
            }
            $data["serverLanguages"] = array();
            foreach ($this->yellow->text->getLanguages() as $language) {
                $data["serverLanguages"][$language] = $this->yellow->text->getTextHtml("languageDescription", $language);
            }
            $data["editUploadExtensions"] = $this->yellow->system->get("editUploadExtensions");
            $data["editKeyboardShortcuts"] = $this->yellow->system->get("editKeyboardShortcuts");
            $data["editToolbarButtons"] = $this->getToolbarButtons("edit");
            $data["emojiawesomeToolbarButtons"] =  $this->getToolbarButtons("emojiawesome");
            $data["fontawesomeToolbarButtons"] =  $this->getToolbarButtons("fontawesome");
        } else {
            $data["editLoginEmail"] = $this->yellow->page->get("editLoginEmail");
            $data["editLoginPassword"] = $this->yellow->page->get("editLoginPassword");
            $data["editLoginRestriction"] = intval($this->isLoginRestriction());
        }
        if (defined("DEBUG") && DEBUG>=1) $data["debug"] = DEBUG;
        return $data;
    }
    
    // Return request strings
    public function getRequestData() {
        $data = array();
        foreach ($_REQUEST as $key=>$value) {
            if ($key=="password" || $key=="authtoken" || $key=="csrftoken" || $key=="actiontoken" || substru($key, 0, 7)=="rawdata") continue;
            $data["request".ucfirst($key)] = trim($value);
        }
        return $data;
    }
    
    // Return text strings
    public function getTextData() {
        $textLanguage = $this->yellow->text->getData("language", $this->language);
        $textEdit = $this->yellow->text->getData("edit", $this->language);
        $textYellow = $this->yellow->text->getData("yellow", $this->language);
        return array_merge($textLanguage, $textEdit, $textYellow);
    }
    
    // Return toolbar buttons
    public function getToolbarButtons($name) {
        if ($name=="edit") {
            $toolbarButtons = $this->yellow->system->get("editToolbarButtons");
            if ($toolbarButtons=="auto") {
                $toolbarButtons = "";
                if ($this->yellow->extensions->isExisting("markdown")) $toolbarButtons = "format, bold, italic, strikethrough, code, separator, list, link, file";
                if ($this->yellow->extensions->isExisting("emojiawesome")) $toolbarButtons .= ", emojiawesome";
                if ($this->yellow->extensions->isExisting("fontawesome")) $toolbarButtons .= ", fontawesome";
                if ($this->yellow->extensions->isExisting("draft")) $toolbarButtons .= ", draft";
                if ($this->yellow->extensions->isExisting("markdown")) $toolbarButtons .= ", preview, markdown";
            }
        } else {
            $toolbarButtons = $this->yellow->system->get("{$name}ToolbarButtons");
        }
        return $toolbarButtons;
    }
    
    // Return end of line format
    public function getEndOfLine($rawData = "") {
        $endOfLine = $this->yellow->system->get("editEndOfLine");
        if ($endOfLine=="auto") {
            $rawData = empty($rawData) ? PHP_EOL : substru($rawData, 0, 4096);
            $endOfLine = strposu($rawData, "\r")===false ? "lf" : "crlf";
        }
        return $endOfLine;
    }
    
    // Return raw data for new page
    public function getRawDataNew($page, $customTitle = false) {
        foreach ($this->yellow->content->path($page->location)->reverse() as $ancestor) {
            if ($ancestor->isExisting("layoutNew")) {
                $name = $this->yellow->lookup->normaliseName($ancestor->get("layoutNew"));
                $location = $this->yellow->content->getHomeLocation($page->location).$this->yellow->system->get("contentSharedDir");
                $fileName = $this->yellow->lookup->findFileFromLocation($location, true).$this->yellow->system->get("editNewFile");
                $fileName = strreplaceu("(.*)", $name, $fileName);
                if (is_file($fileName)) break;
            }
        }
        if (!is_file($fileName)) {
            $name = $this->yellow->lookup->normaliseName($this->yellow->system->get("layout"));
            $location = $this->yellow->content->getHomeLocation($page->location).$this->yellow->system->get("contentSharedDir");
            $fileName = $this->yellow->lookup->findFileFromLocation($location, true).$this->yellow->system->get("editNewFile");
            $fileName = strreplaceu("(.*)", $name, $fileName);
        }
        if (is_file($fileName)) {
            $rawData = $this->yellow->toolbox->readFile($fileName);
            $rawData = preg_replace("/@timestamp/i", time(), $rawData);
            $rawData = preg_replace("/@datetime/i", date("Y-m-d H:i:s"), $rawData);
            $rawData = preg_replace("/@date/i", date("Y-m-d"), $rawData);
            $rawData = preg_replace("/@usershort/i", strtok($this->extension->users->getName($this->userEmail), " "), $rawData);
            $rawData = preg_replace("/@username/i", $this->extension->users->getName($this->userEmail), $rawData);
            $rawData = preg_replace("/@userlanguage/i", $this->extension->users->getLanguage($this->userEmail), $rawData);
        } else {
            $rawData = "---\nTitle: Page\n---\n";
        }
        if ($customTitle) {
            $title = $this->yellow->toolbox->createTextTitle($page->location);
            $rawData = $this->yellow->toolbox->setMetaData($rawData, "title", $title);
        }
        return $rawData;
    }
    
    // Return location for new/modified page
    public function getPageNewLocation($rawData, $pageLocation, $pageNewLocation, $pageMatchLocation = false) {
        $location = empty($pageNewLocation) ? "@title" : $pageNewLocation;
        $location = preg_replace("/@title/i", $this->getPageNewTitle($rawData), $location);
        $location = preg_replace("/@timestamp/i", $this->getPageNewData($rawData, "published", true, "U"), $location);
        $location = preg_replace("/@date/i", $this->getPageNewData($rawData, "published", true, "Y-m-d"), $location);
        $location = preg_replace("/@year/i", $this->getPageNewData($rawData, "published", true, "Y"), $location);
        $location = preg_replace("/@month/i", $this->getPageNewData($rawData, "published", true, "m"), $location);
        $location = preg_replace("/@day/i", $this->getPageNewData($rawData, "published", true, "d"), $location);
        $location = preg_replace("/@tag/i", $this->getPageNewData($rawData, "tag", true), $location);
        $location = preg_replace("/@author/i", $this->getPageNewData($rawData, "author", true), $location);
        if (!preg_match("/^\//", $location)) {
            if ($this->yellow->lookup->isFileLocation($pageLocation) || !$pageMatchLocation) {
                $location = $this->yellow->lookup->getDirectoryLocation($pageLocation).$location;
            } else {
                $location = $this->yellow->lookup->getDirectoryLocation(rtrim($pageLocation, "/")).$location;
            }
        }
        if ($pageMatchLocation) {
            $location = rtrim($location, "/").($this->yellow->lookup->isFileLocation($pageLocation) ? "" : "/");
        }
        return $location;
    }
    
    // Return title for new/modified page
    public function getPageNewTitle($rawData) {
        $title = $this->yellow->toolbox->getMetaData($rawData, "title");
        $titleSlug = $this->yellow->toolbox->getMetaData($rawData, "titleSlug");
        $value = empty($titleSlug) ? $title : $titleSlug;
        $value = $this->yellow->lookup->normaliseName($value, true, false, true);
        return trim(preg_replace("/-+/", "-", $value), "-");
    }
    
    // Return data for new/modified page
    public function getPageNewData($rawData, $key, $filterFirst = false, $dateFormat = "") {
        $value = $this->yellow->toolbox->getMetaData($rawData, $key);
        if ($filterFirst && preg_match("/^(.*?)\,(.*)$/", $value, $matches)) $value = $matches[1];
        if (!empty($dateFormat)) $value = date($dateFormat, strtotime($value));
        if (strempty($value)) $value = "none";
        $value = $this->yellow->lookup->normaliseName($value, true, false, true);
        return trim(preg_replace("/-+/", "-", $value), "-");
    }
    
    // Return file name for new/modified page
    public function getPageNewFile($location, $pageFileName = "", $pagePrefix = "") {
        $fileName = $this->yellow->lookup->findFileFromLocation($location);
        if (!empty($fileName)) {
            if (!is_dir(dirname($fileName))) {
                $path = "";
                $tokens = explode("/", $fileName);
                for ($i=0; $i<count($tokens)-1; ++$i) {
                    if (!is_dir($path.$tokens[$i])) {
                        if (!preg_match("/^[\d\-\_\.]+(.*)$/", $tokens[$i])) {
                            $number = 1;
                            foreach ($this->yellow->toolbox->getDirectoryEntries($path, "/^[\d\-\_\.]+(.*)$/", true, true, false) as $entry) {
                                if ($number!=1 && $number!=intval($entry)) break;
                                $number = intval($entry)+1;
                            }
                            $tokens[$i] = "$number-".$tokens[$i];
                        }
                        $tokens[$i] = $this->yellow->lookup->normaliseName($tokens[$i], false, false, true);
                    }
                    $path .= $tokens[$i]."/";
                }
                $fileName = $path.$tokens[$i];
                $pageFileName = empty($pageFileName) ? $fileName : $pageFileName;
            }
            $prefix = $this->getPageNewPrefix($location, $pageFileName, $pagePrefix);
            if ($this->yellow->lookup->isFileLocation($location)) {
                preg_match("#^(.*)\/(.+?)$#", $fileName, $matches);
                $path = $matches[1];
                $text = $this->yellow->lookup->normaliseName($matches[2], true, true);
                if (preg_match("/^[\d\-\_\.]*$/", $text)) $prefix = "";
                $fileName = $path."/".$prefix.$text.$this->yellow->system->get("contentExtension");
            } else {
                preg_match("#^(.*)\/(.+?)$#", dirname($fileName), $matches);
                $path = $matches[1];
                $text = $this->yellow->lookup->normaliseName($matches[2], true, false);
                if (preg_match("/^[\d\-\_\.]*$/", $text)) $prefix = "";
                $fileName = $path."/".$prefix.$text."/".$this->yellow->system->get("contentDefaultFile");
            }
        }
        return $fileName;
    }
    
    // Return prefix for new/modified page
    public function getPageNewPrefix($location, $pageFileName, $pagePrefix) {
        if (empty($pagePrefix)) {
            if ($this->yellow->lookup->isFileLocation($location)) {
                preg_match("#^(.*)\/(.+?)$#", $pageFileName, $matches);
                $pagePrefix = $matches[2];
            } else {
                preg_match("#^(.*)\/(.+?)$#", dirname($pageFileName), $matches);
                $pagePrefix = $matches[2];
            }
        }
        return $this->yellow->lookup->normalisePrefix($pagePrefix, true);
    }
    
    // Return location for new file
    public function getFileNewLocation($fileNameShort, $pageLocation, $fileNewLocation) {
        $location = empty($fileNewLocation) ? $this->yellow->system->get("editUploadNewLocation") : $fileNewLocation;
        $location = preg_replace("/@timestamp/i", time(), $location);
        $location = preg_replace("/@type/i", $this->yellow->toolbox->getFileType($fileNameShort), $location);
        $location = preg_replace("/@group/i", $this->getFileNewGroup($fileNameShort), $location);
        $location = preg_replace("/@folder/i", $this->getFileNewFolder($pageLocation), $location);
        $location = preg_replace("/@filename/i", strtoloweru($fileNameShort), $location);
        if (!preg_match("/^\//", $location)) {
            $location = $this->yellow->system->get("mediaLocation").$location;
        }
        return $location;
    }
    
    // Return group for new file
    public function getFileNewGroup($fileNameShort) {
        $path = $this->yellow->system->get("mediaDir");
        $fileType = $this->yellow->toolbox->getFileType($fileNameShort);
        $fileName = $this->yellow->system->get(preg_match("/(gif|jpg|png|svg)$/", $fileType) ? "imageDir" : "downloadDir").$fileNameShort;
        preg_match("#^$path(.+?)\/#", $fileName, $matches);
        return strtoloweru($matches[1]);
    }

    // Return folder for new file
    public function getFileNewFolder($pageLocation) {
        $parentTopLocation = $this->yellow->content->getParentTopLocation($pageLocation);
        if ($parentTopLocation==$this->yellow->content->getHomeLocation($pageLocation)) $parentTopLocation .= "home";
        return strtoloweru(trim($parentTopLocation, "/"));
    }
    
    // Return next file name
    public function getFileNext($fileNameShort) {
        preg_match("/^(.*?)(\d*)(\..*?)?$/", $fileNameShort, $matches);
        $fileText = $matches[1];
        $fileNumber = strempty($matches[2]) ? "-2" : $matches[2]+1;
        $fileExtension = $matches[3];
        return $fileText.$fileNumber.$fileExtension;
    }
    
    // Return next title
    public function getTitleNext($rawData) {
        preg_match("/^(.*?)(\d*)$/", $this->yellow->toolbox->getMetaData($rawData, "title"), $matches);
        $titleText = $matches[1];
        $titleNumber = strempty($matches[2]) ? " 2" : $matches[2]+1;
        return $titleText.$titleNumber;
    }
    
    // Normalise text lines, convert line endings
    public function normaliseLines($text, $endOfLine = "lf") {
        if ($endOfLine=="lf") {
            $text = preg_replace("/\R/u", "\n", $text);
        } else {
            $text = preg_replace("/\R/u", "\r\n", $text);
        }
        return $text;
    }
    
    // Create browser cookies
    public function createCookies($scheme, $address, $base, $email) {
        $expire = time() + $this->yellow->system->get("editLoginSessionTimeout");
        $authToken = $this->extension->users->createAuthToken($email, $expire);
        $csrfToken = $this->extension->users->createCsrfToken();
        setcookie("authtoken", $authToken, $expire, "$base/", "", $scheme=="https", true);
        setcookie("csrftoken", $csrfToken, $expire, "$base/", "", $scheme=="https", false);
    }
    
    // Destroy browser cookies
    public function destroyCookies($scheme, $address, $base) {
        setcookie("authtoken", "", 1, "$base/", "", $scheme=="https", true);
        setcookie("csrftoken", "", 1, "$base/", "", $scheme=="https", false);
    }
    
    // Send mail to user
    public function sendMail($scheme, $address, $base, $email, $action) {
        if ($action=="approve") {
            $userName = $this->yellow->system->get("author");
            $userEmail = $this->yellow->system->get("email");
            $userLanguage = $this->extension->getUserLanguage($userEmail);
        } else {
            $userName = $this->extension->users->getName($email);
            $userEmail = $email;
            $userLanguage = $this->extension->getUserLanguage($email);
        }
        if ($action=="welcome" || $action=="goodbye") {
            $url = "$scheme://$address$base/";
        } else {
            $expire = time() + 60*60*24;
            $actionToken = $this->extension->users->createActionToken($email, $action, $expire);
            $url = "$scheme://$address$base"."/action:$action/email:$email/expire:$expire/language:$userLanguage/actiontoken:$actionToken/";
        }
        $prefix = "edit".ucfirst($action);
        $message = $this->yellow->text->getText("{$prefix}Message", $userLanguage);
        $message = strreplaceu("\\n", "\n", $message);
        $message = preg_replace("/@useraccount/i", $email, $message);
        $message = preg_replace("/@usershort/i", strtok($userName, " "), $message);
        $message = preg_replace("/@username/i", $userName, $message);
        $message = preg_replace("/@userlanguage/i", $userLanguage, $message);
        $sitename = $this->yellow->system->get("sitename");
        $mailTo = mb_encode_mimeheader("$userName")." <$userEmail>";
        $mailSubject = mb_encode_mimeheader($this->yellow->text->getText("{$prefix}Subject", $userLanguage));
        $mailHeaders = mb_encode_mimeheader("From: $sitename")." <noreply>\r\n";
        $mailHeaders .= mb_encode_mimeheader("X-Request-Url: $scheme://$address$base")."\r\n";
        $mailHeaders .= "Mime-Version: 1.0\r\n";
        $mailHeaders .= "Content-Type: text/plain; charset=utf-8\r\n";
        $mailMessage = "$message\r\n\r\n$url\r\n-- \r\n$sitename";
        return mail($mailTo, $mailSubject, $mailMessage, $mailHeaders);
    }
    
    // Change content file
    public function editContentFile($page, $action) {
        if (!$page->isError()) {
            foreach ($this->yellow->extensions->extensions as $key=>$value) {
                if (method_exists($value["obj"], "onEditContentFile")) $value["obj"]->onEditContentFile($page, $action);
            }
        }
    }

    // Change media file
    public function editMediaFile($file, $action) {
        if (!$file->isError()) {
            foreach ($this->yellow->extensions->extensions as $key=>$value) {
                if (method_exists($value["obj"], "onEditMediaFile")) $value["obj"]->onEditMediaFile($file, $action);
            }
        }
    }
    
    // Check if meta data has been modified
    public function isMetaModified($pageSource, $pageOther) {
        return substrb($pageSource->rawData, 0, $pageSource->metaDataOffsetBytes) !=
            substrb($pageOther->rawData, 0, $pageOther->metaDataOffsetBytes);
    }
    
    // Check if active
    public function isActive() {
        return $this->active;
    }
    
    // Check if user is logged in
    public function isUser() {
        return !empty($this->userEmail);
    }
    
    // Check if user is webmaster
    public function isUserWebmaster() {
        return !empty($this->userEmail) && $this->userEmail==$this->yellow->system->get("email");
    }
    
    // Check if user with restriction
    public function isUserRestriction() {
        return empty($this->userEmail) || $this->userRestriction;
    }
    
    // Check if login with restriction
    public function isLoginRestriction() {
        return $this->yellow->system->get("editLoginRestriction");
    }
}

class YellowEditUsers {
    public $yellow;     //access to API
    public $users;      //registered users
    
    public function __construct($yellow) {
        $this->yellow = $yellow;
        $this->users = array();
    }

    // Load users from file
    public function load($fileName) {
        if (defined("DEBUG") && DEBUG>=2) echo "YellowEditUsers::load file:$fileName<br/>\n";
        $fileData = $this->yellow->toolbox->readFile($fileName);
        foreach ($this->yellow->toolbox->getTextLines($fileData) as $line) {
            if (preg_match("/^\#/", $line)) continue;
            preg_match("/^\s*(.*?)\s*:\s*(.*?)\s*$/", $line, $matches);
            if (!empty($matches[1]) && !empty($matches[2])) {
                list($hash, $name, $language, $status, $stamp, $modified, $errors, $pending, $home) = explode(",", $matches[2]);
                $this->set($matches[1], $hash, $name, $language, $status, $stamp, $modified, $errors, $pending, $home);
                if (defined("DEBUG") && DEBUG>=3) echo "YellowEditUsers::load email:$matches[1]<br/>\n";
            }
        }
    }

    // Save user to file
    public function save($fileName, $email, $password = "", $name = "", $language = "", $status = "", $stamp = "", $modified = "", $errors = "", $pending = "", $home = "") {
        if (!empty($password)) $hash = $this->createHash($password);
        if ($this->isExisting($email)) {
            $email = strreplaceu(",", "-", $email);
            $hash = strreplaceu(",", "-", empty($hash) ? $this->users[$email]["hash"] : $hash);
            $name = strreplaceu(",", "-", empty($name) ? $this->users[$email]["name"] : $name);
            $language = strreplaceu(",", "-", empty($language) ? $this->users[$email]["language"] : $language);
            $status = strreplaceu(",", "-", empty($status) ? $this->users[$email]["status"] : $status);
            $stamp = strreplaceu(",", "-", empty($stamp) ? $this->users[$email]["stamp"] : $stamp);
            $modified = strreplaceu(",", "-", empty($modified) ? time() : $modified);
            $errors = strreplaceu(",", "-", empty($errors) ? "0" : $errors);
            $pending = strreplaceu(",", "-", empty($pending) ? $this->users[$email]["pending"] : $pending);
            $home = strreplaceu(",", "-", empty($home) ? $this->users[$email]["home"] : $home);
        } else {
            $email = strreplaceu(",", "-", empty($email) ? "none" : $email);
            $hash = strreplaceu(",", "-", empty($hash) ? "none" : $hash);
            $name = strreplaceu(",", "-", empty($name) ? $this->yellow->system->get("sitename") : $name);
            $language = strreplaceu(",", "-", empty($language) ? $this->yellow->system->get("language") : $language);
            $status = strreplaceu(",", "-", empty($status) ? "active" : $status);
            $stamp = strreplaceu(",", "-", empty($stamp) ? $this->createStamp() : $stamp);
            $modified = strreplaceu(",", "-", empty($modified) ? time() : $modified);
            $errors = strreplaceu(",", "-", empty($errors) ? "0" : $errors);
            $pending = strreplaceu(",", "-", empty($pending) ? "none" : $pending);
            $home = strreplaceu(",", "-", empty($home) ? $this->yellow->system->get("editUserHome") : $home);
        }
        $this->set($email, $hash, $name, $language, $status, $stamp, $modified, $errors, $pending, $home);
        $fileData = $this->yellow->toolbox->readFile($fileName);
        foreach ($this->yellow->toolbox->getTextLines($fileData) as $line) {
            preg_match("/^\s*(.*?)\s*:\s*(.*?)\s*$/", $line, $matches);
            if (!empty($matches[1]) && $matches[1]==$email) {
                $fileDataNew .= "$email: $hash,$name,$language,$status,$stamp,$modified,$errors,$pending,$home\n";
                $found = true;
            } else {
                $fileDataNew .= $line;
            }
        }
        if (!$found) $fileDataNew .= "$email: $hash,$name,$language,$status,$stamp,$modified,$errors,$pending,$home\n";
        return $this->yellow->toolbox->createFile($fileName, $fileDataNew);
    }
    
    // Remove user from file
    public function remove($fileName, $email) {
        unset($this->users[$email]);
        $fileData = $this->yellow->toolbox->readFile($fileName);
        foreach ($this->yellow->toolbox->getTextLines($fileData) as $line) {
            preg_match("/^\s*(.*?)\s*:\s*(.*?)\s*$/", $line, $matches);
            if (!empty($matches[1]) && $matches[1]==$email) continue;
            $fileDataNew .= $line;
        }
        return $this->yellow->toolbox->createFile($fileName, $fileDataNew);
    }
    
    // Set user data
    public function set($email, $hash, $name, $language, $status, $stamp, $modified, $errors, $pending, $home) {
        $this->users[$email] = array();
        $this->users[$email]["email"] = $email;
        $this->users[$email]["hash"] = $hash;
        $this->users[$email]["name"] = $name;
        $this->users[$email]["language"] = $language;
        $this->users[$email]["status"] = $status;
        $this->users[$email]["stamp"] = $stamp;
        $this->users[$email]["modified"] = $modified;
        $this->users[$email]["errors"] = $errors;
        $this->users[$email]["pending"] = $pending;
        $this->users[$email]["home"] = $home;
    }
    
    // Check user authentication from email and password
    public function checkAuthLogin($email, $password) {
        $algorithm = $this->yellow->system->get("editUserHashAlgorithm");
        return $this->isExisting($email) && $this->users[$email]["status"]=="active" &&
            $this->yellow->toolbox->verifyHash($password, $algorithm, $this->users[$email]["hash"]);
    }

    // Check user authentication from tokens
    public function checkAuthToken($authToken, $csrfTokenExpected, $csrfTokenReceived, $ignoreCsrfToken) {
        $signature = "$5y$".substrb($authToken, 0, 96);
        $email = $this->getAuthEmail($authToken);
        $expire = $this->getAuthExpire($authToken);
        return $expire>time() && $this->isExisting($email) && $this->users[$email]["status"]=="active" &&
            $this->yellow->toolbox->verifyHash($this->users[$email]["hash"]."auth".$expire, "sha256", $signature) &&
            ($this->yellow->toolbox->verifyToken($csrfTokenExpected, $csrfTokenReceived) || $ignoreCsrfToken);
    }
    
    // Check action token
    public function checkActionToken($actionToken, $email, $action, $expire) {
        $signature = "$5y$".$actionToken;
        return $expire>time() && $this->isExisting($email) &&
            $this->yellow->toolbox->verifyHash($this->users[$email]["hash"].$action.$expire, "sha256", $signature);
    }
           
    // Create authentication token
    public function createAuthToken($email, $expire) {
        $signature = $this->yellow->toolbox->createHash($this->users[$email]["hash"]."auth".$expire, "sha256");
        if (empty($signature)) $signature = "padd"."error-hash-algorithm-sha256";
        return substrb($signature, 4).$this->getStamp($email).dechex($expire);
    }
    
    // Create action token
    public function createActionToken($email, $action, $expire) {
        $signature = $this->yellow->toolbox->createHash($this->users[$email]["hash"].$action.$expire, "sha256");
        if (empty($signature)) $signature = "padd"."error-hash-algorithm-sha256";
        return substrb($signature, 4);
    }
    
    // Create CSRF token
    public function createCsrfToken() {
        return $this->yellow->toolbox->createSalt(64);
    }
    
    // Create password hash
    public function createHash($password) {
        $algorithm = $this->yellow->system->get("editUserHashAlgorithm");
        $cost = $this->yellow->system->get("editUserHashCost");
        $hash = $this->yellow->toolbox->createHash($password, $algorithm, $cost);
        if (empty($hash)) $hash = "error-hash-algorithm-$algorithm";
        return $hash;
    }
    
    // Create user stamp
    public function createStamp() {
        $stamp = $this->yellow->toolbox->createSalt(20);
        while ($this->getAuthEmail("none", $stamp)) {
            $stamp = $this->yellow->toolbox->createSalt(20);
        }
        return $stamp;
    }
    
    // Return user email from authentication, timing attack safe email lookup
    public function getAuthEmail($authToken, $stamp = "") {
        if (empty($stamp)) $stamp = substrb($authToken, 96, 20);
        foreach ($this->users as $key=>$value) {
            if ($this->yellow->toolbox->verifyToken($value["stamp"], $stamp)) $email = $key;
        }
        return $email;
    }
    
    // Return expiration time from authentication
    public function getAuthExpire($authToken) {
        return hexdec(substrb($authToken, 96+20));
    }
    
    // Return user hash
    public function getHash($email) {
        return $this->isExisting($email) ? $this->users[$email]["hash"] : "";
    }
    
    // Return user name
    public function getName($email) {
        return $this->isExisting($email) ? $this->users[$email]["name"] : "";
    }

    // Return user language
    public function getLanguage($email) {
        return $this->isExisting($email) ? $this->users[$email]["language"] : "";
    }
    
    // Return user status
    public function getStatus($email) {
        return $this->isExisting($email) ? $this->users[$email]["status"] : "";
    }
    
    // Return user stamp
    public function getStamp($email) {
        return $this->isExisting($email) ? $this->users[$email]["stamp"] : "";
    }
    
    // Return user modified
    public function getModified($email) {
        return $this->isExisting($email) ? $this->users[$email]["modified"] : "";
    }

    // Return user errors
    public function getErrors($email) {
        return $this->isExisting($email) ? $this->users[$email]["errors"] : "";
    }

    // Return user pending
    public function getPending($email) {
        return $this->isExisting($email) ? $this->users[$email]["pending"] : "";
    }
    
    // Return user home
    public function getHome($email) {
        return $this->isExisting($email) ? $this->users[$email]["home"] : "";
    }
    
    // Return number of users
    public function getNumber() {
        return count($this->users);
    }

    // Return user data
    public function getData() {
        $data = array();
        foreach ($this->users as $key=>$value) {
            $name = $value["name"];
            $status = $value["status"];
            if (preg_match("/\s/", $name)) $name = "\"$name\"";
            if (preg_match("/\s/", $status)) $status = "\"$status\"";
            $data[$key] = "$value[email] $name $status";
        }
        uksort($data, "strnatcasecmp");
        return $data;
    }
    
    // Check if user is taken
    public function isTaken($email) {
        $taken = false;
        if ($this->isExisting($email)) {
            $status = $this->users[$email]["status"];
            $reserved = $this->users[$email]["modified"] + 60*60*24;
            if ($status=="active" || $status=="inactive" || $reserved>time()) $taken = true;
        }
        return $taken;
    }
    
    // Check if user exists
    public function isExisting($email) {
        return !is_null($this->users[$email]);
    }
}
    
class YellowEditMerge {
    public $yellow;     //access to API
    const ADD = "+";    //merge types
    const MODIFY = "*";
    const REMOVE = "-";
    const SAME = " ";
    
    public function __construct($yellow) {
        $this->yellow = $yellow;
    }
    
    // Merge text, null if not possible
    public function merge($textSource, $textMine, $textYours, $showDiff = false) {
        if ($textMine!=$textYours) {
            $diffMine = $this->buildDiff($textSource, $textMine);
            $diffYours = $this->buildDiff($textSource, $textYours);
            $diff = $this->mergeDiff($diffMine, $diffYours);
            $output = $this->getOutput($diff, $showDiff);
        } else {
            $output = $textMine;
        }
        return $output;
    }
    
    // Build differences to common source
    public function buildDiff($textSource, $textOther) {
        $diff = array();
        $lastRemove = -1;
        $textStart = 0;
        $textSource = $this->yellow->toolbox->getTextLines($textSource);
        $textOther = $this->yellow->toolbox->getTextLines($textOther);
        $sourceEnd = $sourceSize = count($textSource);
        $otherEnd = $otherSize = count($textOther);
        while ($textStart<$sourceEnd && $textStart<$otherEnd && $textSource[$textStart]==$textOther[$textStart]) {
            ++$textStart;
        }
        while ($textStart<$sourceEnd && $textStart<$otherEnd && $textSource[$sourceEnd-1]==$textOther[$otherEnd-1]) {
            --$sourceEnd;
            --$otherEnd;
        }
        for ($pos=0; $pos<$textStart; ++$pos) {
            array_push($diff, array(YellowEditMerge::SAME, $textSource[$pos], false));
        }
        $lcs = $this->buildDiffLCS($textSource, $textOther, $textStart, $sourceEnd-$textStart, $otherEnd-$textStart);
        for ($x=0,$y=0,$xEnd=$otherEnd-$textStart,$yEnd=$sourceEnd-$textStart; $x<$xEnd || $y<$yEnd;) {
            $max = $lcs[$y][$x];
            if ($y<$yEnd && $lcs[$y+1][$x]==$max) {
                array_push($diff, array(YellowEditMerge::REMOVE, $textSource[$textStart+$y], false));
                if ($lastRemove==-1) $lastRemove = count($diff)-1;
                ++$y;
                continue;
            }
            if ($x<$xEnd && $lcs[$y][$x+1]==$max) {
                if ($lastRemove==-1 || $diff[$lastRemove][0]!=YellowEditMerge::REMOVE) {
                    array_push($diff, array(YellowEditMerge::ADD, $textOther[$textStart+$x], false));
                    $lastRemove = -1;
                } else {
                    $diff[$lastRemove] = array(YellowEditMerge::MODIFY, $textOther[$textStart+$x], false);
                    ++$lastRemove;
                    if (count($diff)==$lastRemove) $lastRemove = -1;
                }
                ++$x;
                continue;
            }
            array_push($diff, array(YellowEditMerge::SAME, $textSource[$textStart+$y], false));
            $lastRemove = -1;
            ++$x;
            ++$y;
        }
        for ($pos=$sourceEnd;$pos<$sourceSize; ++$pos) {
            array_push($diff, array(YellowEditMerge::SAME, $textSource[$pos], false));
        }
        return $diff;
    }
    
    // Build longest common subsequence
    public function buildDiffLCS($textSource, $textOther, $textStart, $yEnd, $xEnd) {
        $lcs = array_fill(0, $yEnd+1, array_fill(0, $xEnd+1, 0));
        for ($y=$yEnd-1; $y>=0; --$y) {
            for ($x=$xEnd-1; $x>=0; --$x) {
                if ($textSource[$textStart+$y]==$textOther[$textStart+$x]) {
                    $lcs[$y][$x] = $lcs[$y+1][$x+1]+1;
                } else {
                    $lcs[$y][$x] = max($lcs[$y][$x+1], $lcs[$y+1][$x]);
                }
            }
        }
        return $lcs;
    }
    
    // Merge differences
    public function mergeDiff($diffMine, $diffYours) {
        $diff = array();
        $posMine = $posYours = 0;
        while ($posMine<count($diffMine) && $posYours<count($diffYours)) {
            $typeMine = $diffMine[$posMine][0];
            $typeYours = $diffYours[$posYours][0];
            if ($typeMine==YellowEditMerge::SAME) {
                array_push($diff, $diffYours[$posYours]);
            } elseif ($typeYours==YellowEditMerge::SAME) {
                array_push($diff, $diffMine[$posMine]);
            } elseif ($typeMine==YellowEditMerge::ADD && $typeYours==YellowEditMerge::ADD) {
                $this->mergeConflict($diff, $diffMine[$posMine], $diffYours[$posYours], false);
            } elseif ($typeMine==YellowEditMerge::MODIFY && $typeYours==YellowEditMerge::MODIFY) {
                $this->mergeConflict($diff, $diffMine[$posMine], $diffYours[$posYours], false);
            } elseif ($typeMine==YellowEditMerge::REMOVE && $typeYours==YellowEditMerge::REMOVE) {
                array_push($diff, $diffMine[$posMine]);
            } elseif ($typeMine==YellowEditMerge::ADD) {
                array_push($diff, $diffMine[$posMine]);
            } elseif ($typeYours==YellowEditMerge::ADD) {
                array_push($diff, $diffYours[$posYours]);
            } else {
                $this->mergeConflict($diff, $diffMine[$posMine], $diffYours[$posYours], true);
            }
            if (defined("DEBUG") && DEBUG>=2) echo "YellowEditMerge::mergeDiff $typeMine $typeYours pos:$posMine\t$posYours<br/>\n";
            if ($typeMine==YellowEditMerge::ADD || $typeYours==YellowEditMerge::ADD) {
                if ($typeMine==YellowEditMerge::ADD) ++$posMine;
                if ($typeYours==YellowEditMerge::ADD) ++$posYours;
            } else {
                ++$posMine;
                ++$posYours;
            }
        }
        for (;$posMine<count($diffMine); ++$posMine) {
            array_push($diff, $diffMine[$posMine]);
            $typeMine = $diffMine[$posMine][0];
            $typeYours = " ";
            if (defined("DEBUG") && DEBUG>=2) echo "YellowEditMerge::mergeDiff $typeMine $typeYours pos:$posMine\t$posYours<br/>\n";
        }
        for (;$posYours<count($diffYours); ++$posYours) {
            array_push($diff, $diffYours[$posYours]);
            $typeYours = $diffYours[$posYours][0];
            $typeMine = " ";
            if (defined("DEBUG") && DEBUG>=2) echo "YellowEditMerge::mergeDiff $typeMine $typeYours pos:$posMine\t$posYours<br/>\n";
        }
        return $diff;
    }
    
    // Merge potential conflict
    public function mergeConflict(&$diff, $diffMine, $diffYours, $conflict) {
        if (!$conflict && $diffMine[1]==$diffYours[1]) {
            array_push($diff, $diffMine);
        } else {
            array_push($diff, array($diffMine[0], $diffMine[1], true));
            array_push($diff, array($diffYours[0], $diffYours[1], true));
        }
    }
    
    // Return merged text, null if not possible
    public function getOutput($diff, $showDiff = false) {
        $output = "";
        if (!$showDiff) {
            for ($i=0; $i<count($diff); ++$i) {
                if ($diff[$i][0]!=YellowEditMerge::REMOVE) $output .= $diff[$i][1];
                $conflict |= $diff[$i][2];
            }
        } else {
            for ($i=0; $i<count($diff); ++$i) {
                $output .= $diff[$i][2] ? "! " : $diff[$i][0]." ";
                $output .= $diff[$i][1];
            }
        }
        return !$conflict ? $output : null;
    }
}
