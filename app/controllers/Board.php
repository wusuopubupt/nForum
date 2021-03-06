<?php
/**
 * Board controller for nforum
 *
 * @author xw
 */
class BoardController extends NF_Controller {

    private $_board;

    public function init(){
        parent::init();
        if(!isset($this->params['name'])){
            $this->error(ECode::$BOARD_NONE);
        }

        try{
            $boardName = $this->params['name'];
            if(preg_match("/^\d+$/", $boardName))
                throw new BoardNullException();
            load('model/board');
            $this->_board = Board::getInstance($boardName);
            if($this->_board->isDir())
                throw new BoardNullException();
        }catch(BoardNullException $e){
            $this->error(ECode::$BOARD_UNKNOW);
        }

        if('mode' === $this->getRequest()->getActionName()){
            $mode = (int)trim($this->params['num']);
            if(!$this->_board->setMode($mode))
                $this->error(ECode::$BOARD_NOPERM);
        }else if('index' !== $this->getRequest()->getActionName() && null !== ($mode = Cookie::getInstance()->read('BMODE'))){
            if(!$this->_board->setMode($mode))
                $this->error(ECode::$BOARD_NOPERM);
        }
        if(!$this->_board->hasReadPerm(User::getInstance())){
            if(!NF_Session::getInstance()->isLogin)
                $this->requestLogin();
            $this->error(ECode::$BOARD_NOPERM);
        }
        $this->_board->setOnBoard();
    }

    public function indexAction(){
        $this->js[] = "forum.board.js";
        $this->css[] = "board.css";

        if(Board::$THREAD != Cookie::getInstance()->read('BMODE'))
            Cookie::getInstance()->write('BMODE', Board::$THREAD, false);

        $this->_board->setMode(Board::$NORMAL);
        $this->cache(true, $this->_board->wGetTime(), 0);

        $this->_board->setMode(Board::$THREAD);

        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"文章列表");

        load('inc/pagination');
        $pageBar = "";
        $p = isset($this->params['url']['p'])?$this->params['url']['p']:1;
        $pagination = new Pagination($this->_board, c("pagination.threads"));
        $threads = $pagination->getPage($p);
        $u = User::getInstance();
        if($bm = $u->isBM($this->_board) || $u->isAdmin())
            $this->js[] = "forum.manage.js";
        $info = false;
        $curTime = strtotime(date("Y-m-d", time()));
        $pageArticle = c("pagination.article");
        foreach($threads as $v){
            $page = ceil($v->articleNum / $pageArticle);
            $last = $v->LAST;
            $postTime = ($curTime > $v->POSTTIME)?date("Y-m-d", $v->POSTTIME):(date("H:i:s", $v->POSTTIME)."&emsp;");
            $replyTime = ($curTime > $last->POSTTIME)?date("Y-m-d", $last->POSTTIME):(date("H:i:s", $last->POSTTIME)."&emsp;");
            $info[] = array(
                "tag" => $this->_getTag($v),
                "title" => nforum_html($v->TITLE),
                "poster" => $v->isSubject()?$v->OWNER:"原帖已删除",
                "postTime" => $postTime,
                "gid" => $v->ID,
                "last" => $last->OWNER,
                "replyTime" => $replyTime,
                "num" => $v->articleNum - 1,
                "page" => $page,
                "att" => $v->hasAttach()
            );
        }
        $this->title = c('site.name').'-'.$this->_board->DESC;
        $this->set("info", $info);
        $link = "{$this->base}/board/{$this->_board->NAME}?p=%page%";
        $this->set("pageBar", $pagination->getPageBar($p, $link));
        $this->set("pagination", $pagination);

        $bms = split(" ", $this->_board->BM);
        foreach($bms as &$v){
            if(preg_match("/[^0-9a-zA-Z]/", $v)){
                $v = array($v, false);
            }else{
                $v = array($v, true);
            }
        }

        $this->set("todayNum", $this->_board->getTodayNum());
        $this->set("curNum", $this->_board->CURRENTUSERS);
        if(isset($this->_board->MAXONLINE)){
            $this->set("maxNum", $this->_board->MAXONLINE);
            $this->set("maxTime", date("Y-m-d H:i:s", $this->_board->MAXTIME));
        }
        $this->set("bms", $bms);
        $this->set("bName", $this->_board->NAME);
        $this->set("bm", $bm);
        $this->set("tmpl", $this->_board->isTmplPost());
        $this->set("hasVote", count($this->_board->getVotes()) != 0);
        //for default search day
        $this->set("searchDay", c("search.day"));
        //for elite path
        $this->set("elitePath", urlencode($this->_board->getElitePath()));
        $this->jsr[] = "window.user_post=" . ($this->_board->hasPostPerm($u) && !$this->_board->isDeny($u)?"true":"false") . ";";
    }

    public function modeAction(){
        $this->js[] = "forum.board.js";
        $this->css[] = "board.css";

        $mode = $this->_board->getMode();
        if($mode != Cookie::getInstance()->read('BMODE'))
            Cookie::getInstance()->write('BMODE', $mode, false);

        $this->cache(true, $this->_board->wGetTime(), 0);
        switch($mode){
            case BOARD::$NORMAL:
                $tmp = '经典模式';
                break;
            case BOARD::$DIGEST:
                $tmp = '文摘模式';
                break;
            case BOARD::$MARK:
                $tmp = '保留模式';
                break;
            case BOARD::$DELETED:
                $tmp = '回收模式';
                break;
            case BOARD::$JUNK:
                $tmp = '纸篓模式';
                break;
            case BOARD::$ORIGIN:
                $tmp = '原作模式';
                break;
            default:
                $tmp = '主题模式';
        }

        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>$tmp);

        load('inc/pagination');
        $pageBar = "";
        $p = isset($this->params['url']['p'])?$this->params['url']['p']:1;
        $pagination = new Pagination($this->_board, c("pagination.threads"));
        $articles = $pagination->getPage($p);
        $u = User::getInstance();
        if($bm = $u->isBM($this->_board) || $u->isAdmin())
            $this->js[] = "forum.manage.js";
        $info = false;
        $curTime = strtotime(date("Y-m-d", time()));
        $sort = $this->_board->isSortMode();
        foreach($articles as $v){
            $postTime = ($curTime > $v->POSTTIME)?date("Y-m-d", $v->POSTTIME):(date("H:i:s", $v->POSTTIME)."&emsp;");
            $info[] = array(
                "tag" => $this->_getTag($v),
                "title" => nforum_html($v->TITLE),
                "poster" => $v->OWNER,
                "postTime" => $postTime,
                "id" => $sort?$v->ID:$v->getPos(),
                "gid" => $v->GROUPID,
                "att" => $v->hasAttach()
            );
        }
        $this->title = c('site.name').'-'.$this->_board->DESC;
        $this->set("info", $info);
        $link = "{$this->base}/board/{$this->_board->NAME}/mode/{$mode}?p=%page%";
        $this->set("pageBar", $pagination->getPageBar($p, $link));
        $this->set("pagination", $pagination);

        $bms = split(" ", $this->_board->BM);
        foreach($bms as &$v){
            if(preg_match("/[^0-9a-zA-Z]/", $v)){
                $v = array($v, false);
            }else{
                $v = array($v, true);
            }
        }

        $this->set("todayNum", $this->_board->getTodayNum());
        $this->set("curNum", $this->_board->CURRENTUSERS);
        if(isset($this->_board->MAXONLINE)){
            $this->set("maxNum", $this->_board->MAXONLINE);
            $this->set("maxTime", date("Y-m-d H:i:s", $this->_board->MAXTIME));
        }
        $this->set("bms", $bms);
        $this->set("bName", $this->_board->NAME);
        $this->set("bm", $u->isBM($this->_board));
        $this->set("tmpl", $this->_board->isTmplPost());
        $this->set("hasVote", count($this->_board->getVotes()) != 0);
        $this->set("mode", (int)$mode);
        //for default search day
        $this->set("searchDay", c("search.day"));
        //for elite path
        $this->set("elitePath", urlencode($this->_board->getElitePath()));
        $this->jsr[] = "window.user_post=" . ($this->_board->hasPostPerm($u) && !$this->_board->isDeny($u)?"true":"false") . ";";
    }

    public function voteAction(){
        $this->requestLogin();
        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"投票");
        if(isset($this->params['num'])){
            $num = (int) $this->params['num'];
            $vote = $this->_board->getVote($num);
            if($vote === false)
                $this->error();
            $vote['start'] = date('Y-m-d H:i:s', $vote['start']);
            $vote['day'] .= '天';
            $vote['title'] = nforum_html($vote['title']);
            if(is_array($vote['val'])){
                foreach($vote['val'] as &$v)
                    $v = nforum_html($v);
            }
            $this->set($vote);
            $this->set("num", $num);
            $this->set("bName", $this->_board->NAME);
            $this->render("vote_que");
            return;
        }
        $votes = $this->_board->getVotes();
        $info = array();
        foreach($votes as $k=>$v){
            $info[$k]['owner'] = $v['USERID'];
            $info[$k]['title'] = nforum_html($v['TITLE']);
            $info[$k]['start'] = date('Y-m-d H:i:s', $v['DATE']);
            $info[$k]['type'] = $v['TYPE'];
            $info[$k]['day'] = $v['MAXDAY'].'天';
        }
        $this->set("info", $info);
        $this->set("bName", $this->_board->NAME);
    }

    public function ajax_voteAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $this->requestLogin();

        if(!isset($this->params['num']))
            $this->error(ECode::$BOARD_VOTEFAIL);

        $num = (int) $this->params['num'];
        $vote = $this->_board->getVote($num);
        if($vote === false)
            $this->error(ECode::$BOARD_VOTEFAIL);

        $v = @$this->params['form']['v'];
        $msg = @$this->params['form']['msg'];
        $msg = nforum_iconv('utf-8', $this->encoding, $msg);
        $val1 = $val2 = 0;
        if($vote['type'] == '数字'){
            $val1 = (int)$v;
        }else if($vote['type'] == '复选'){
            if(count((array)$v) > $vote['limit'])
                $this->error(ECode::$BOARD_VOTEFAIL);
            foreach((array)$v as $k=>$v){
                if($k < 32)
                    $val1 += 1 << $k;
                else
                    $val2 += 1 << ($k - 32);
            }
        }else if($vote['type'] != '问答'){
            $v = intval($v);
            if($v < 32)
                $val1 = 1 << $v;
            else
                $val2 = 1 << ($v - 32);
        }
        if(!$this->_board->vote($num, $val1, $val2, $msg))
            $this->error(ECode::$BOARD_VOTEFAIL);

        $ret['ajax_code'] = ECode::$BOARD_VOTESUCCESS;
        $ret['default'] = '/board/' .  $this->_board->NAME;
        $mode = $this->_board->getMode();
        if($mode != BOARD::$THREAD) $ret['default'] .= '/mode/' . $mode;
        $ret['list'][] = array("text" => '版面:' . $this->_board->DESC, "url" => $ret['default']);
        $ret['list'][] = array("text" => '投票列表', "url" => '/board/' .  $this->_board->NAME . '/vote/');
        $ret['list'][] = array("text" => c("site.name"), "url" => c("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function denylistAction(){
        $this->requestLogin();
        $this->cache(false);
        $u = User::getInstance();
        try {
            $ret = $this->_board->getDeny();
        } catch(BoardDenyException $e) {
            $this->error($e->getMessage());
        }
        $this->_getNotice();
        $this->notice[] = array("url"=>"", "text"=>"封禁名单");
        $this->title = c('site.name').'-'.$this->_board->DESC;
        $this->js[] = "forum.board.js";
        $this->css[] = "board.css";
        $this->set('bName', $this->_board->NAME);
        $this->set('data', $ret);
        $this->set('maxday', $u->isAdmin()?70:14);
    }

    public function ajax_adddenyAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $this->requestLogin();
        $u = User::getInstance();
        if (!isset($this->params['form']['id']))
            $this->error(ECode::$DENY_NOID);
        if (!isset($this->params['form']['reason']))
            $this->error(ECode::$DENY_NOREASON);
        if (!isset($this->params['form']['day']))
            $this->error(ECode::$DENY_INVALIDDAY);
        $id = $this->params['form']['id'];
        $reason = nforum_iconv('utf-8', $this->encoding, $this->params['form']['reason']);
        $day = intval($this->params['form']['day']);
        if ($day < 1)
            $this->error(ECode::$DENY_INVALIDDAY);
        try {
            $this->_board->addDeny($id, $reason, $day);
        } catch (BoardDenyException $e) {
            $this->error($e->getMessage());
        }
        $ret['ajax_code'] = ECode::$SYS_AJAXOK;
        $ret['default'] = '/board/' . $this->_board->NAME . '/denylist';
        $ret['list'][] = array('text' => '版面封禁列表:' . $this->_board->DESC, 'url' => '/board/' . $this->_board->NAME . '/denylist');
        $ret['list'][] = array('text' => '版面:' . $this->_board->DESC, 'url' => '/board/' . $this->_board->NAME);
        $ret['list'][] = array("text" => c("site.name"), "url" => c("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function ajax_moddenyAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $this->requestLogin();
        $u = User::getInstance();
        if (!isset($this->params['form']['id']))
            $this->error(ECode::$DENY_NOID);
        if (!isset($this->params['form']['reason']))
            $this->error(ECode::$DENY_NOREASON);
        if (!isset($this->params['form']['day']))
            $this->error(ECode::$DENY_INVALIDDAY);
        $id = $this->params['form']['id'];
        $reason = nforum_iconv('utf-8', $this->encoding, $this->params['form']['reason']);
        $day = intval($this->params['form']['day']);
        if ($day < 1)
            $this->error(ECode::$DENY_INVALIDDAY);
        try {
            $this->_board->modDeny($id, $reason, $day);
        } catch (BoardDenyException $e) {
            $this->error($e->getMessage());
        }
        $ret['ajax_code'] = ECode::$SYS_AJAXOK;
        $ret['default'] = '/board/' . $this->_board->NAME . '/denylist';
        $ret['list'][] = array('text' => '版面封禁列表:' . $this->_board->DESC, 'url' => '/board/' . $this->_board->NAME . '/denylist');
        $ret['list'][] = array('text' => '版面:' . $this->_board->DESC, 'url' => '/board/' . $this->_board->NAME);
        $ret['list'][] = array("text" => c("site.name"), "url" => c("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function ajax_deldenyAction(){
        if(!$this->getRequest()->isPost())
            $this->error(ECode::$SYS_REQUESTERROR);
        $this->requestLogin();
        $u = User::getInstance();
        if (!isset($this->params['form']['id']))
            $this->error(ECode::$DENY_NOID);
        $id = $this->params['form']['id'];
        try {
            $this->_board->delDeny($id);
        } catch (BoardDenyException $e) {
            $this->error($e->getMessage());
        }
        $ret['ajax_code'] = ECode::$SYS_AJAXOK;
        $ret['default'] = '/board/' . $this->_board->NAME . '/denylist';
        $ret['list'][] = array('text' => '版面封禁列表:' . $this->_board->DESC, 'url' => '/board/' . $this->_board->NAME . '/denylist');
        $ret['list'][] = array('text' => '版面:' . $this->_board->DESC, 'url' => '/board/' . $this->_board->NAME);
        $ret['list'][] = array("text" => c("site.name"), "url" => c("site.home"));
        $this->set('no_html_data', $ret);
    }

    public function ajax_denyreasonsAction() {
        $this->cache(false);
        $u = User::getInstance();
        $ret = array();
        if ($u->isBM($this->_board) || $u->isAdmin())
            $ret = $this->_board->getDenyReasons();
        $this->set('no_ajax_info', true);
        $this->set('no_html_data', $ret);
    }

    private function _getTag($threads){
        if($threads->isTop()){
            return "T";
        }
        if($threads->isB()){
            return "B";
        }
        if($threads->isM()){
            return "M";
        }
        if($threads->isNoRe()){
            return ";";
        }
        if($threads->isG()){
            return "G";
        }
        if($threads->articleNum > 1000){
            return "L3";
        }
        if($threads->articleNum > 100){
            return "L2";
        }
        if($threads->articleNum > 10)
            return "L";
        return "N";
    }

    private function _getNotice(){
        $root = c("section.{$this->_board->SECNUM}");
        $this->notice[] = array("url"=>"/section/{$this->_board->SECNUM}", "text"=>$root[0]);
        $boards = array(); $tmp = $this->_board;
        while(!is_null($tmp = $tmp->getDir())){
            $boards[] = array("url"=>"/section/{$tmp->NAME}", "text"=>$tmp->DESC);
        }
        foreach($boards as $v)
            $this->notice[] = $v;
        $url = "/board/{$this->_board->NAME}";
        $mode = $this->_board->getMode();
        if($mode != BOARD::$THREAD) $url .= '/mode/' . $mode;
        $this->notice[] = array("url"=>$url, "text"=>$this->_board->DESC);
    }
}
