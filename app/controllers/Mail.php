<?php
/**
 * Mail controller for nforum
 *
 * @author xw
 */
load('model/mail');
class MailController extends NF_Controller {

    public function init(){
        parent::init();
        $this->requestLogin();
        $this->notice[] = array("url"=>"/mail", "text"=>"用户信件");
    }

    public function indexAction(){
        $this->js[] = "forum.mail.js";
        $this->css[] = "mail.css";

        $type = MailBox::$IN;
        $pageBar = "";
        if(isset($this->params['type'])){
            $type = $this->params['type'];
        }
        try{
            $mailBox = new MailBox(User::getInstance(), $type);
        }catch(MailBoxNullException $e){
            $this->error(ECode::$MAIL_NOBOX);
        }
        $p = isset($this->params['url']['p'])?$this->params['url']['p']:1;

        load("inc/pagination");
        try{
            $pagination = new Pagination($mailBox, c("pagination.mail"));
            $mails = $pagination->getPage($p);
        }catch(MailDataNullException $e){
            $this->error(ECode::$MAIL_NOMAIL);
        }
        if($mailBox->getTotalNum() > 0){
            $info = array();
            foreach($mails as $v){
                $info[] = array(
                    "tag" => $this->_getTag($v),
                    "read" => $v->isRead(),
                    "num" => $v->num,
                    "sender" => $v->OWNER,
                    "title" => nforum_html($v->TITLE),
                    "time" => date("Y-m-d H:i:s", $v->POSTTIME),
                    "size" => nforum_size_format($v->EFFSIZE)
                );
            }
            $this->set("info", $info);
        }
        $link = "{$this->base}/mail/{$type}?p=%page%";
        $this->set("pageBar", $pagination->getPageBar($p, $link));
        $this->set("pagination", $pagination);
        $this->set("type", $type);
        $this->set("desc", $mailBox->desc);

        $limit = MailBox::getLimit(User::getInstance());
        $space = MailBox::getSpace();
        $this->set("limit", $limit);
        $this->set("space", $space);
        $this->set("percent", 100 * ($space > $limit['space']?1:$space/$limit['space']));

    }

    public function ajax_detailAction(){
        if(!isset($this->params['type']))
            $this->error(ECode::$MAIL_NOBOX);
        if(!isset($this->params['num']))
            $this->error(ECode::$MAIL_NOMAIL);

        $type = $this->params['type'];
        $num = $this->params['num'];
        try{
            $box = new MailBox(User::getInstance(), $type);
            $mail = Mail::getInstance($num, $box);
        }catch(Exception $e){
            $this->error(ECode::$MAIL_NOMAIL);
        }
        $this->cache(true, $mail->getMTime(), 0);
        $mail->setRead();
        $content = $mail->getHtml(true);
        if(c("ubb.parse")){
            load('inc/ubb');
            $content = preg_replace("'^(.*?<br \/>.*?<br \/>)'e", "XUBB::remove('\\1')", $content);
            $content = XUBB::parse($content);
        }
        $ret = array('num' => $mail->num
            ,'type' => $box->type
            ,'content' => $content
        );
        $this->set("no_html_data", $ret);
    }

    public function sendAction(){
        $this->_sendInit();
        $this->js[] = "forum.post.js";
        $this->css[] = "post.css";
        $this->notice[] = array("url"=>"/mail/send", "text"=>"撰写邮件");

        $u = User::getInstance();
        $title = $content = "";

        if(isset($this->params['url']['id'])){
            try{
                $user = User::getInstance($this->params['url']['id']);
            }catch(UserNullException $e){
                $this->error(ECode::$USER_NOID);
            }
            $this->set("rid", $user->userid);
        }else{
            load('model/friend');
            //show my friends
            $f = new Friend($u);
            $friends = $f->getRecord(1, $f->getTotalNum());
            $ret = array();
            foreach($friends as $v){
                $ret[$v->userid] = $v->userid;
            }
            $this->set("friends", $ret);
        }
        $sigOption = array();
        foreach(range(0, $u->signum) as $v){
            if($v == 0)
                $sigOption["$v"] = "不使用签名档";
            else
                $sigOption["$v"] = "使用第{$v}个";
        }
        $sigOption["-1"] = "使用随机签名档";
        $title = nforum_html($title);
        $content = nforum_html($content);
        $this->set("title", $title);
        $this->set("content", $content);
        $this->set("sigOption", $sigOption);
        $this->set("sigNow", $u->signature);
        $this->set("bak", $u->getCustom("mailbox_prop", 0));
    }

    public function replyAction(){
        $mail = $this->_sendInit();
        $this->js[] = "forum.post.js";
        $this->css[] = "post.css";
        $this->notice[] = array("url"=>"/mail/send", "text"=>"回复邮件");
        $u = User::getInstance();
        if(false === $mail){
            //reply article
            if(!isset($this->params['type']))
                $this->error(ECode::$MAIL_NOBOX);
            if(!isset($this->params['num']))
                $this->error(ECode::$MAIL_NOMAIL);
            $type = $this->params['type'];
            $num = $this->params['num'];
            try{
                load(array('model/board', 'model/article'));
                $b = Board::getInstance($type);
                if(!$b->hasReadPerm($u))
                    $this->error(ECode::$BOARD_NOPERM);
                $mail = Article::getInstance($num, $b);
            }catch(Exception $e){
                $this->error(ECode::$MAIL_NOMAIL);
            }
        }

        if(!strncmp($mail->TITLE, "Re: ", 4))
            $title = $mail->TITLE;
        else
            $title = "Re: " . $mail->TITLE;
        $content = "\n".$mail->getRef();
        //remove ref ubb tag
        load('inc/ubb');
        $content = XUBB::remove($content);
        $this->set("rid", $mail->OWNER);

        $sigOption = array();
        foreach(range(0, $u->signum) as $v){
            if($v == 0)
                $sigOption["$v"] = "不使用签名档";
            else
                $sigOption["$v"] = "使用第{$v}个";
        }
        $sigOption["-1"] = "使用随机签名档";
        $title = nforum_html($title);
        $content = nforum_html($content);
        $this->set("title", $title);
        $this->set("content", $content);
        $this->set("sigOption", $sigOption);
        $this->set("sigNow", $u->signature);
        $this->set("bak", $u->getCustom("mailbox_prop", 0));

        $this->render('send');
    }

    public function ajax_sendAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);

        $mail = $this->_sendInit();
        $title = $content = '';
        $sig = User::getInstance()->signature;
        if(isset($this->params['form']['title']))
            $title = trim($this->params['form']['title']);
        if(isset($this->params['form']['content']))
            $content = $this->params['form']['content'];
        if(isset($this->params['form']['signature']))
            $sig = intval($this->params['form']['signature']);
        $bak = isset($this->params['form']['backup'])?1:0;
        $title = nforum_iconv('utf-8', $this->encoding, $title);
        $content = nforum_iconv('utf-8', $this->encoding, $content);
        try{
            if(false === $mail){
                //send new
                if(!isset($this->params['form']['id']))
                    $this->error(ECode::$MAIL_NOID);
                $id = trim($this->params['form']['id']);
                Mail::send($id, $title, $content, $sig, $bak);
            }else{
                //reply
                $mail->reply($title, $content, $sig, $bak);
            }
        }catch(MailSendException $e){
            $this->error($e->getMessage());
        }
        $ret['ajax_code'] = ECode::$MAIL_SENDOK;
        $ret['default'] = "/mail";
        $ret['list'][] = array("text" => "收件箱", "url" => "/mail");
        $this->set('no_html_data', $ret);
    }

    public function ajax_forwardAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);

        $mail = $this->_sendInit();
        if(false === $mail)
            $this->error(ECode::$MAIL_NOMAIL);
        if(!isset($this->params['form']['id']))
            $this->error(ECode::$MAIL_NOID);
        $id = trim($this->params['form']['id']);
        $noansi = isset($this->params['form']['noansi']);
        $big5 = isset($this->params['form']['big5']);
        try{
            $mail->forward($id, $noansi, $big5);
        }catch(MailSendException $e){
            $this->error($e->getMessage());
        }
        $ret['ajax_code'] = ECode::$MAIL_FORWARDOK;
        $this->set('no_html_data', $ret);
    }

    public function ajax_deleteAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);

        if(!isset($this->params['type']))
            $this->error(ECode::$MAIL_NOBOX);

        $type = $this->params['type'];
        try{
            $box = new MailBox(User::getInstance(), $type);
        }catch(MailBoxNullException $e){
            $this->error(ECode::$MAIL_NOBOX);
        }
        if(!isset($this->params['num'])){
            if(isset($this->params['form']['all'])){
                //delete all
                try{
                    $all = $box->getRecord(1, $box->getTotalNum());
                    foreach($all as $mail)
                        $mail->delete();
                }catch(Exception $e){
                    continue;
                }
            }else{
                //delete normal
                foreach($this->params['form'] as $k=>$v){
                    if(!preg_match("/m_/", $k))
                        continue;
                    $num = split("_", $k);
                    try{
                        $mail = Mail::getInstance($num[1], $box);
                        $mail->delete();
                    }catch(Exception $e){
                        continue;
                    }
                }
            }
        }else{
            try{
                //delete single
                $num = $this->params['num'];
                $mail = Mail::getInstance($num, $box);
                if(!$mail->delete())
                    $this->error(ECode::$MAIL_DELETEERROR);
            }catch(Exception $e){
                $this->error(ECode::$MAIL_DELETEERROR);
            }
        }
        $ret['ajax_code'] = ECode::$MAIL_DELETEOK;
        $ret['default'] = "/mail/$type";
        $ret['list'][] = array("text" => $box->desc, "url" => "/mail/$type");
        $this->set('no_html_data', $ret);
    }

    public function ajax_previewAction(){
        if(!isset($this->params['form']['title']) || !isset($this->params['form']['content'])){
            $this->error();
        }

        $subject = rawurldecode(trim($this->params['form']['title']));
        $subject = nforum_iconv('utf-8', $this->encoding, $subject);
        if(strlen($subject) > 60)
            $subject = nforum_fix_gbk(substr($subject,0,60));
        $subject = nforum_html($subject);

        $content = $this->params['form']['content'];
        $content = nforum_iconv('utf-8', $this->encoding, $content);
        $content = preg_replace("/\n/", "<br />", nforum_html($content));
        if(c("ubb.parse")){
            load('inc/ubb');
            $content = XUBB::parse($content);
        }
        $this->set('no_html_data', array("subject"=>$subject,"content"=>$content));
    }

    //if has mail return it or false
    //set type,num if has mail
    private function _sendInit(){
        if(!Mail::canSend())
            $this->error(ECode::$MAIL_SENDERROR);
        $type = $num = false;
        if(isset($this->params['type']))
            $type = $this->params['type'];
        if(isset($this->params['num']))
            $num = $this->params['num'];
        else if(isset($this->params['form']['num']))
            $num = $this->params['form']['num'];

        if(empty($type) || false === $num)
            return false;

        try{
            $mail = MAIL::getInstance($num, new MailBox(User::getInstance(),$type));
            $this->set('num', $num);
            $this->set('type', $type);
        }catch(UserNullException $e){
            $this->error(ECode::$USER_NOID);
        }catch(Exception $e){
            return false;
        }

        return $mail;
    }

    private function _getTag($mail){
        $ret = "";
        $ret .= $mail->isRead()?"n" : "N" ;
        $ret .= $mail->isM()?"m" : " " ;
        $ret .= $mail->isReply()?"r" : " ";
        $ret .= $mail->hasAttach()?"@" : " ";
        return $ret;
    }
}
