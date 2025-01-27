<?php 

class adminLog extends Controller{
    public $actionList = array();
	function __construct() {
        parent::__construct();
        $this->model = Model('SystemLog');
    }

    /**
     * 操作类型列表
     * this.actions()
     * @return void
     */
    public function typeList(){
        $typeList = $this->model->typeListAll();
        $list = array(
            'all'   => array('id' => 'all',  'text' => LNG('common.all')),
            'file'  => array('id' => 'file', 'text' => LNG('admin.log.typeFile')),
            'user'  => array('id' => 'user', 'text' => LNG('admin.log.typeUser')),
            'admin' => array('id' => 'admin','text' => LNG('admin.manage')),
        );
        foreach($typeList as $type => $name) {
            $action = explode('.', $type);
            $mod = $action[0];
            if(!isset($list[$mod])) continue;
            $list[$mod]['children'][] = array('id' => $type,'text' => $name);
		}
        $fileList = array(
            array('id' => 'explorer.index.zipDownload', 'text' => LNG('admin.log.downFolder')),
            array('id' => 'explorer.index.fileOut', 'text' => LNG('admin.log.downFile')),
            array('id' => 'explorer.index.fileDownload', 'text' => LNG('admin.log.downFile')),
            array('id' => 'explorer.fav.add', 'text' => LNG('explorer.addFav')),
            array('id' => 'explorer.fav.del', 'text' => LNG('explorer.delFav')),
        );
		$list['file']['children'] = array_merge($list['file']['children'], $fileList);
        $list = $this->typeListMerge($list);
        show_json($list);
	}
	
	// 合并操作日志类型;
	private function typeListMerge($list){
		$mergeList = array(
			'file' => array(
				// 'file.edit,file.rename'  => LNG('admin.log.editFile'),
				// 'file.mkdir,file.mkfile' => '新建文件(夹)',
				'file.copy,file.move,file.moveOut' => LNG('log.file.move'),
				'explorer.fav.add,explorer.fav.del' => LNG('log.file.fav'),
				'explorer.index.fileOut,explorer.index.fileDownload' => LNG('admin.log.downFile'),
				'file.shareLinkAdd,file.shareLinkRemove' => LNG('log.file.shareLink'),
				'file.shareToAdd,file.shareToRemove' => LNG('log.file.shareTo'),
			),
			'user' => array(
				'user.setting.setHeadImage,user.setting.setUserInfo' => LNG('log.user.edit'),
			),
			'admin' => array(
				'admin.group.add,admin.group.edit,admin.group.remove,admin.group.status,admin.group.sort,admin.group.switch' => LNG('log.group.edit'),
				'admin.member.add,admin.member.edit,admin.member.remove,admin.member.addGroup,admin.member.removeGroup,admin.member.switchGroup,admin.member.status' => LNG('log.member.edit'),
				'admin.role.add,admin.role.edit,admin.role.remove' => LNG('log.role.edit'),
				'admin.auth.add,admin.auth.edit,admin.auth.remove' => LNG('log.auth.edit'),
			),
		);
		foreach($list as $listKey => $item) {
			if(!$item['children'] || !$mergeList[$item['id']]) continue;
			$actionMake = array();
			foreach ($mergeList[$item['id']] as $actions => $text) {
				$actionArr = explode(',',$actions);				
				$actionMake[$actions] = false;//isMerged 是否合并;
				foreach ($actionArr as $action) {
					$actionMake[$action] = array('data'=>array('id'=> $actions,'text'=> $text),'actions'=>$actions);
				}
			}
			
			$children = array();
			foreach ($item['children'] as $childItem) {
				$action = $childItem['id'];
				if( isset($actionMake[$action]) ){
					$item = $actionMake[$action];
					if( !$actionMake[$item['actions']] ){
						$children[] = $item['data'];
						$actionMake[$item['actions']] = true;
					}
				}else{
					$children[] = $childItem;
				}
			}
			// pr($item,$children,$actionMake);exit;
			$list[$listKey]['children'] = $children;
		}
		$list = array_values($list);
		return $list;
	}
	

	/**
     * 后台管理-日志列表
     * @return void
     */
    public function get(){
        $data = Input::getArray(array(
            'timeFrom'  => array('check' => 'require'),
            'timeTo'    => array('check' => 'require'),
            'userID'    => array('default' => ''),
            'type'      => array('default' => ''),
            'ip'        => array('default' => null),
        ));
		
		// 部门管理员, 只能查询自己为部门管理员部门下的成员操作日志;
		if(!_get($GLOBALS,'isRoot')){
			$filter = Action("filter.UserGroup");
			if($data['userID'] && !$filter->allowChangeUser($data['userID'])){
				show_json(LNG('explorer.noPermissionAction'),false);
			}
			$groupAdmin = $filter->userAdminGroup();
			if(!$data['userID'] && !in_array('1',$groupAdmin)){
				$groupAll = Model('Group')->groupChildrenAll($groupAdmin);
				$userAll  = Model('User')->groupUserAll($groupAll);
				if(!$userAll){
					show_json(array());
				}
				$data['userID'] = array('in',$userAll);
			}
		}
		
        $res = $this->model->get($data);
        if(empty($res)) show_json(array());
        show_json($res['list'], true, $res['pageInfo']);
    }

    /**
     * 记录日志
     * @param boolean $data
     * @return void
     */
    public function log($data=false){
        if (isset($this->in['disableLog']) && $this->in['disableLog'] == '1') return;
        $typeList = $this->model->typeListAll();
        if(!isset($typeList[ACTION])) return;
		if($GLOBALS['loginLogSaved'] ==1) return;
        $actionList = array(
            'user.index.logout',
            'user.index.loginSubmit',
        );
        // 操作日志
        if(!in_array(ACTION, $actionList)){
            // 文件类的操作，此处只收集这3个
            if(MOD == 'explorer') {
                $act = ST . '.' . ACT;
                $func = array('fav.add', 'fav.del', 'index.fileOut', 'index.fileDownload', 'index.zipDownload');
                if(!in_array($act, $func)) return;
                if (in_array(ACT, array('fileOut', 'fileDownload'))) { // 多线程下载，或某些浏览器会请求多次
                    if (!$this->checkDownload()) return;
                } else if (ACT == 'zipDownload') {
                    if (isset($this->in['zipClient']) && $this->in['zipClient'] == '1') {
                        $data = false;  // 前端压缩下载会返回列表，故下方以$this->in赋值
                    }
                }
            }
            if(!is_array($data)) {
                $data = $this->in;
                unset($data['URLremote'], $data['URLrouter'], $data['HTTP_DEBUG_URL'], $data['CSRF_TOKEN'], $data[str_replace(".", "/", ACTION)]);
            }
        }
        // 第三方绑定
        if(ACTION == 'user.index.loginSubmit'){
            if (!is_array($data)) return;
            return $this->loginLog();
        }
        return $this->model->addLog(ACTION, $data);
    }

    /**
     * 检测下载
     * 无range：小文件不传，大文件可能有多个请求，前期不传——60s差不多能完成（60s内多次下载也只记一次）
     * 有range：浏览器下载多次请求时，可能是start或end与size相近（size-1），end-start>1，此时在无range请求时记录
     * @return void
     */
    private function checkDownload(){
        if (!isset($_SERVER['HTTP_RANGE'])) {
            $key = md5(json_encode($this->in));
            if (Cache::get($key)) return false;
            Cache::set($key, 60);
            return true;
        }
        $start = $end = 0;
        $bytes = explode('-', str_replace('bytes=', '', $_SERVER['HTTP_RANGE']));
        $start = intval($bytes[0]);
        $end   = isset($bytes[1]) ? intval($bytes[1]) : 0;
        return abs($end - $start) > 1 ? false : true;   // app:0-;客户端:0-1
    }

    /**
     * 登录日志
     * @param string $action
     * @param [type] $ip
     * @return void
     */
    public function loginLog(){
		if($GLOBALS['loginLogSaved'] == 1) return;
		$GLOBALS['loginLogSaved'] = 1;
        $data = array(
            'is_wap' => is_wap(),
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        );
        if(isset($this->in['HTTP_X_PLATFORM'])) {
            $data['is_wap'] = true;
            $data['HTTP_X_PLATFORM'] = $this->in['HTTP_X_PLATFORM'];
        }
        return $this->model->addLog('user.index.loginSubmit', $data);
    }

    /**
     * 个人中心-用户文档日志
     * @return void
     */
    public function userLog(){
        $userID = Input::get('userID', 'int');
        // 获取文件操作类型
		$typeList = $this->model->typeListAll();
		$types = array();
		foreach($typeList as $key => $value) {
			if(strpos($key, 'file.') === 0) $types[] = $key;
		}
		$add = array(
			'explorer.index.fileOut',
			'explorer.index.fileDownload',
			'explorer.index.zipDownload',
			'explorer.fav.add',
			'explorer.fav.del'
		);
        $types = array_merge($types, $add);
		$data = array(
			'userID' => $userID,
			'type'	 => implode(',', $types)
		);
		$res = $this->model->get($data);
		foreach($res['list'] as $i => &$item) {
			$value = array(
				'type' 			=> $item['type'],
				'createTime'	=> $item['createTime'],
				'title'			=> $item['title'],
				'address'		=> $item['address']
			);
			$item['desc'] = is_array($item['desc']) ? $item['desc']:array();
			$item = array_merge($value, $item['desc']);
		};unset($item);
		show_json($res);
    }
    /**
     * 个人中心-用户登录日志
     * @return void
     */
    public function userLogLogin(){
        $data = Input::getArray(array(
            'type'      => array('check' => 'require'),
            'userID'    => array('check' => 'require'),
        ));
        $res = $this->model->get($data);
        if(empty($res)) show_json(array());
        show_json($res['list'], true, $res['pageInfo']);
    }
}