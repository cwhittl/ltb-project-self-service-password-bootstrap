<?php
#==============================================================================
# LTB Self Service Password
#
# Copyright (C) 2009 Clement OUDOT
# Copyright (C) 2009 LTB-project.org
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# GPL License: http://www.gnu.org/licenses/gpl.txt
#
#==============================================================================

# This page is called to change password

#==============================================================================
# POST parameters
#==============================================================================
# Initiate vars
$result = "";
$login = "";
$confirmpassword = "";
$newpassword = "";
$oldpassword = "";
$ldap = "";
$userdn = "";
if (!isset($pwd_forbidden_chars)) { $pwd_forbidden_chars=""; }
$mail = "";

if (isset($_POST["confirmpassword"]) and $_POST["confirmpassword"]) { $confirmpassword = $_POST["confirmpassword"]; }
 else { $result = "confirmpasswordrequired"; }
if (isset($_POST["newpassword"]) and $_POST["newpassword"]) { $newpassword = $_POST["newpassword"]; }
 else { $result = "newpasswordrequired"; }
if (isset($_POST["oldpassword"]) and $_POST["oldpassword"]) { $oldpassword = $_POST["oldpassword"]; }
 else { $result = "oldpasswordrequired"; }
if (isset($_REQUEST["login"]) and $_REQUEST["login"]) { $login = $_REQUEST["login"]; }
 else { $result = "loginrequired"; }

# Strip slashes added by PHP
$login = stripslashes_if_gpc_magic_quotes($login);
$oldpassword = stripslashes_if_gpc_magic_quotes($oldpassword);
$newpassword = stripslashes_if_gpc_magic_quotes($newpassword);
$confirmpassword = stripslashes_if_gpc_magic_quotes($confirmpassword);

# Check the entered username for characters that our installation doesn't support
if ( $result === "" ) {
    $result = check_username_validity($login,$login_forbidden_chars);
}

# Match new and confirm password
if ( $newpassword != $confirmpassword ) { $result="nomatch"; }

#==============================================================================
# Check reCAPTCHA
#==============================================================================
if ( $result === "" ) {
    if ( $use_recaptcha ) {
        $resp = recaptcha_check_answer ($recaptcha_privatekey,
                                $_SERVER["REMOTE_ADDR"],
                                $_POST["recaptcha_challenge_field"],
                                $_POST["recaptcha_response_field"]);
        if (!$resp->is_valid) {
            $result = "badcaptcha";
            error_log("Bad reCAPTCHA attempt with user $login");
        }
    }
}

#==============================================================================
# Check old password
#==============================================================================
if ( $result === "" ) {

    # Connect to LDAP
    $ldap = ldap_connect($ldap_url);
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

    # Bind
    if ( isset($ldap_binddn) && isset($ldap_bindpw) ) {
        $bind = ldap_bind($ldap, $ldap_binddn, $ldap_bindpw);
    } else {
        $bind = ldap_bind($ldap);
    }

    $errno = ldap_errno($ldap);
    if ( $errno ) {
        $result = "ldaperror";
        error_log("LDAP - Bind error $errno  (".ldap_error($ldap).")");
    } else {
    
    # Search for user
    $ldap_filter = str_replace("{login}", $login, $ldap_filter);
    $search = ldap_search($ldap, $ldap_base, $ldap_filter);

    $errno = ldap_errno($ldap);
    if ( $errno ) {
        $result = "ldaperror";
        error_log("LDAP - Search error $errno  (".ldap_error($ldap).")");
    } else {

    # Get user DN
    $entry = ldap_first_entry($ldap, $search);
    $userdn = ldap_get_dn($ldap, $entry);

    if( !$userdn ) {
        $result = "badcredentials";
        error_log("LDAP - User $login not found");
    } else {
    
    # Get user email for notification
    if ( $notify_on_change ) {
        $mailValues = ldap_get_values($ldap, $entry, $mail_attribute);
        if ( $mailValues["count"] > 0 ) {
            $mail = $mailValues[0];
        }
    }

    # Check objectClass to allow samba and shadow updates
    $ocValues = ldap_get_values($ldap, $entry, 'objectClass');
    if ( !in_array( 'sambaSamAccount', $ocValues ) ) {
        $samba_mode = false;
    }
    if ( !in_array( 'shadowAccount', $ocValues ) ) {
        $shadow_options['update_shadowLastChange'] = false;
    }

    # Bind with old password
    $bind = ldap_bind($ldap, $userdn, $oldpassword);
    $errno = ldap_errno($ldap);
    if ( $errno ) {
        $result = "badcredentials";
        error_log("LDAP - Bind user error $errno  (".ldap_error($ldap).")");
    } else {

    # Rebind as Manager if needed
    if ( $who_change_password == "manager" ) {
        $bind = ldap_bind($ldap, $ldap_binddn, $ldap_bindpw);
    }

    }}}}

}

#==============================================================================
# Check password strength
#==============================================================================
if ( $result === "" ) {
    $result = check_password_strength( $newpassword, $oldpassword, $pwd_policy_config );
}


#==============================================================================
# Change password
#==============================================================================
if ( $result === "" ) {
    $result = change_password($ldap, $userdn, $newpassword, $ad_mode, $ad_options, $samba_mode, $shadow_options, $hash, $who_change_password, $oldpassword);
}

#==============================================================================
# HTML
#==============================================================================
?>

<div class="result alert alert-<?php echo get_criticity($result) ?>">
    <?php echo $messages[$result]; ?>
</div>

<?php if ( $result !== "passwordchanged" ) { ?>

<?php
if ( $show_help ) {
    echo "<div  class=\"result alert alert-info help\"><p>";
    echo $messages["changehelp"];
    echo "</p>";
    echo "</div>\n";
    if (strlen($messages['changehelpextramessage']) > 0) {
        echo "<p>" . $messages['changehelpextramessage'] . "</p>";
    }
    if ( $use_questions or $use_tokens or $use_sms ) {
        echo '<nav id="myNavbar" class="navbar navbar-default" role="navigation">
        <!-- Brand and toggle get grouped for better mobile display -->
        <div class="container">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
                    <span class="sr-only">Toggle navigation</span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#">'.$messages["changehelpreset"].'</a>
            </div>
            <!-- Collect the nav links, forms, and other content for toggling -->
            <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
                <ul class="nav navbar-nav">';
                    if ( $use_questions ) {
            echo "<li>" . $messages["changehelpquestions"] ."</li>";
        }
        if ( $use_tokens ) {
            echo "<li>" . $messages["changehelptoken"] ."</li>";
        }
        if ( $use_sms ) {
            echo "<li>" . $messages["changehelpsms"] ."</li>";
        }
echo '                </ul>
            </div><!-- /.navbar-collapse -->
        </div>
    </nav>';

    }
    
}
?>

<?php
if ($pwd_show_policy_pos === 'above') {
    show_policy($messages, $pwd_policy_config, $result);
}
?>

<form action="#" method="post" class="form-horizontal">
    <input class="form-control" placeholder="<?php echo $messages["login"]; ?>" type="text" name="login" value="<?php echo htmlentities($login) ?>" /></td></tr>
    <input class="form-control" placeholder="<?php echo $messages["oldpassword"]; ?>" type="password" name="oldpassword" />
    <input class="form-control" placeholder="<?php echo $messages["newpassword"]; ?>" type="password" name="newpassword" />
    <input class="form-control" placeholder="<?php echo $messages["confirmpassword"]; ?>" type="password" name="confirmpassword" />
<?php if ($use_recaptcha) { ?>
    <div class="row">
<?php echo selfservice_recaptha_get_html($recaptcha_publickey, null, $recaptcha_ssl,$recaptcha_theme,$lang); ?>
    </div>
<?php } ?>
    <input class="btn btn-lg btn-primary btn-block" type="submit" value="<?php echo $messages['submit']; ?>" /></td></tr>
</form>

<?php
if ($pwd_show_policy_pos === 'below') {
    show_policy($messages, $pwd_policy_config, $result);
}
?>

<?php } else {

    # Notify password change
    if ($mail and $notify_on_change) {
        $data = array( "login" => $login, "mail" => $mail, "password" => $newpassword);
        if ( !send_mail($mail, $mail_from, $messages["changesubject"], $messages["changemessage"], $data) ) {
            error_log("Error while sending change email to $mail (user $login)");
        }
    }

    if (strlen($messages['passwordchangedextramessage']) > 0) {
        echo "<div class=\"result " . get_criticity($result) . "\">";
        echo $messages['passwordchangedextramessage'];
        echo "</div>\n";
    }

}
?>
