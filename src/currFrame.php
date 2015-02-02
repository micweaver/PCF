<?php  
/**
 * @author   lizhonghua
 * @version  0.9
 * @desc   PCF PHP并发框架
 */

 declare(ticks = 1);
 abstract  class CurrFrame {
	
	public $framedir = 'currFrameData';
	public $msgqueuefile = 'currFrameData/msgqueue'; //消息文件
	public $processingfile = 'currFrameData/processing';//正在处理中的消息
	public $completefile = 'currFrameData/compeleted';//已经完成的消息
	public $hangfile = 'currFrameData/hang';//暂停的消息
	public $monitor = 'monitor'; 
	
	public $processdir = 'currFrameData/process'; 
	public $processlogdir = 'currFrameData/log';
	
	public $currMsg;//当前进程处理的消息
	public $currProcess;//当前进程处理到的进度
	
	public $lockh ;

	const MSG_END = '_msgend_';
	const PROCESS_END = '_processend_';
	
	const MAX_PROCESS_NUM = 100; //最大并发数
	
	private $fixpronum =0;
	private $isneemsg = true; //是否需要产生消息,消息由其它地方产生时可设置为false,比如消息队列kafka的consumer
	private $isend = false;
	
	private static $count = 0;
	
	
	private function _init(){
	    $this->currMsg == NULL;
	    $this->currProcess == NULL;
	    $this->lockh = NULL;
	  
	    pcntl_signal(SIGHUP,array($this,'sig_hup_handler'));
	    pcntl_signal(SIGTERM,array($this,'sig_hup_handler'));
	    //register_shutdown_function(array($this,'sig_hup_handler'));
	    
	    if(!file_exists($this->framedir)){
	        mkdir($this->framedir);
	    }
	    
	    if(!file_exists($this->processdir)){
	        mkdir($this->processdir);
	    }
	    
	    if(!file_exists($this->processlogdir)){
	        mkdir($this->processlogdir);
	    }
	    
	    touch($this->msgqueuefile);
	    touch($this->processingfile);
	    touch($this->hangfile);
	    touch($this->completefile);
	}
	public function __construct(){
		$this->_init();
	}
	
	public function getHelp(){
		
		return <<<EOF
usage:
 php scriptname start  //启动进程，用户代码配置进程数
 php scriptname restart  //重新启动所有进程
 php scriptname stop  //停止所有进程
 php scriptname batch batchnum //启动 batchnum个进程
 php scriptname batch 0 // batchnum传0杀死所有进程
 php scriptname clean // 删除框架产生的数据文件,慎用

EOF;
	}
	
	public function run(){
		$pnum = intval($GLOBALS['argc']);
		if($pnum < 2) {
		    echo $this->getHelp();
			self::exit_fail('lack param');
		}
		
		$cmd = $GLOBALS['argv'][1];
		switch ($cmd){
			case 'batch' : //调整运行中的进程数为 $GLOBALS['argv'][2]
				$batchnum = intval($GLOBALS['argv'][2]);
				if($batchnum > self::MAX_PROCESS_NUM){
				    self::exit_fail('too many process,max process num:'.self::MAX_PROCESS_NUM);
				}
				$this->batch($batchnum);
				break;   
			case 'start' :  //可能启动进程数目不足
			    $nownum = $this->getProcessNum();
			    if($nownum > 0){
			        echo  "{$nownum} process is running".PHP_EOL;
			        $this->print_running_process();
			        exit;
			    }
			    $this->destroyFile();
			    if($this->fixpronum > 0){
			        $this->batch($this->fixpronum);
			    }
			    break;
			case 'restart' : //重新启动所有进程
			    $this->restartAll();
			    break;
			case 'stop' : 
			    $nownum = $this->getProcessNum();
			    if($nownum == 0){
			        exit("no process is running".PHP_EOL);
			    }
			    $this->batch(0);
			    break;
    	                case 'msg' :   //表示执行消息  $GLOBALS['argv'][2] ，由框架自动触发，不要手动运行该命令
	    	            $this->currMsg = $GLOBALS['argv'][2];
	    	            $this->start();
	    	            $this->end();
	    	            break;
			case 'clean' :  //删除框架产生的数据文件
			    $nownum = $this->getProcessNum();
			    if($nownum > 0){
			        exit("{$nownum} process is running".PHP_EOL);
			    }
				$this->destroyFile();
				$this->isend = true;
				exit('rm data success'.PHP_EOL);
			default:
				echo $this->getHelp();
				$this->isend = true;
				exit();
		}
		
		
		if(in_array($cmd,array('batch','start','restart','stop'))) {
		    usleep(100000);
		    $this->print_running_process();
		}
		
	}
	
	public function print_running_process(){
	    $cmd = "ps -ef | grep ". $GLOBALS['argv'][0].' | grep -v batch | grep -v start | grep -v restart | grep -v stop | grep -v grep';
	    system($cmd);
	    echo PHP_EOL;
	}
	/**
	 * 生产消息,每当没有要处理的消息时会尝试生产新的消息，有个标志标记所有消息已处理完
	 * 新消息的产生由用户代码实现
	 */
	abstract public function produce();
	
	/**
	 * 处理消息,对每一个消息的具体处理由用户代码实现
	 * @param string $msg 要处理的消息
	 * @param string $pos 消息处理到的进度
	 */
   abstract public function process($msg,$pos = NULL);
	
	/**
	 * 对最后的结果进行处理
	 * 比如合并最后的文件或者发送通知邮件等
	 */
	public function output(){
		//用户代码选择性实现
	
	}
	
	/**
	 *  开始运行
	*/
	public function start(){
	    $arrMsg = array_merge((array)file($this->processingfile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),(array)file($this->completefile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
	    if(in_array($this->currMsg, $arrMsg)) self::exit_fail('msg was handled');
	
	    $arrPro = $this->getRunningProcess();
	    if(isset($arrPro[$this->currMsg])) self::exit_fail('msg is running');
	    if($this->restart()) return ;
	    $this->msg_start();
	    $start = time();
	    $this->process($this->currMsg,NULL);
	    $consume = time()-$start;
	    $this->writeLog($this->currMsg.':'.$consume.'s','consume',false);
	
	}
	
	/**
	 * 开始运行后相关消息文件处理
	 */
	public function msg_start(){
	
	    $this->LockAppend($this->processingfile, $this->currMsg);
	    $this->deleteMsg($this->msgqueuefile, $this->currMsg);
	}
	
	
	public function setProcessNum($num,$add_num =0){
	    $arrPro = $this->getRunningProcess();
	    $pronum = $this->getProcessNum();
	    if($num > $pronum){
	        $pnum = $num - $pronum;
	        $pnum = $add_num > $pnum ? $add_num : $pnum;
	        while ($pnum--){
	            $msg = $this->getNextMsg();
	            if($msg === NULL){
	                self::exit_fail('no more msg');
	            }
	            
	            $this->deleteMsg($this->msgqueuefile, $msg);
	            $this->startNextProcess($msg);
	        }
	    } elseif($num < $pronum) {
	        $pnum = $pronum-$num;
	        while ($pnum--){
	            $pro = array_pop($arrPro);
	            posix_kill($pro['pid'], SIGHUP);
	            $this->writeLog("hup msg {$pro['msg']}",$this->monitor,false);
	            if(empty($arrPro)) break;
	        }
	    }
	    
	}
	
	public function getProcessNum(){
	    $arrPro = $this->getRunningProcess();
	    return  count($arrPro);
	}
	

	public function destroyFile(){
	    exec('rm -rf '.$this->framedir,$output);
	}
	public function restartAll(){
	    
	    $nownum = $this->getProcessNum();
	    $pronum = $nownum > $this->fixpronum ? $nownum : $this->fixpronum;
	    $this->setProcessNum(0);
	    echo "stop all process : {$nownum}".PHP_EOL;
	    $this->destroyFile();
	    sleep(1); //等待所有进程都退出
	    $this->setProcessNum($pronum,$pronum);
	    usleep(500000); //等待所有进程都启动
	    $nownum = $this->getProcessNum();
	    if($nownum < $pronum) {
	        echo "!! restart fail nownum:{$nownum}".PHP_EOL;
	    } else {
	        echo "restart all process".PHP_EOL;
	    }
 	}
 	
	/**
	 * 启动N个进程
	 * @param int $batchnum 要启动的进程个数，最终运行的进程数等于 $batchnum,这可能要启动新的进程或暂停一定数目已启动进程
	 */
	public function batch($batchnum){
	    $num = $this->getProcessNum();
		$this->setProcessNum($batchnum);
		if($batchnum > 0) {
		    echo ("start  {$batchnum} process success\n");
		} else {
		    echo ("stop all process({$num}) success\n");
		}
		
	}
	
	
	/**
	 * 消息业务逻辑处理完的结尾处理，包括获得下一个消息，并启动下一个进程处理该消息
	 * 当前进程会自动结束
	 */
	public function end(){
		
		$this->stop();
		/*
		 * 判断是不是最后一个进程，并进行结果综合处理
		 */
		if($this->chechMsgEnd()){
		  $nownum = $this->getProcessNum();
		  if($nownum == 0){
			  $arrMsg = (array)file($this->msgqueuefile);
			  if(count($arrMsg) == 1 && trim($arrMsg[0]) == self::MSG_END){
			     $this->output();
			  }
		  }
		}
		
		if($this->lock()) {
		  $msg = $this->getNextMsg();
		  $this->unLock();
		}
		if(empty($msg)) {
			self::exit_success();
		}
		$this->startNextProcess($msg);
		
		self::exit_success();

	}
	
	/**
	 * 获取当前正在运行的进程，得到正在处理中的消息
	 */
	public function getRunningProcess(){
		
		$cmd = " ps -ef | grep ". $GLOBALS['argv'][0].' | grep -v batch | grep -v grep';
		
		exec($cmd,$arrRes);
		$arrProcess = array();
		
		$cpid = posix_getpid(); 
		
		foreach ($arrRes as $val){
			$arrT = preg_split('/\s+/', $val);
			if($arrT[9] != 'msg') continue;
			if($arrT[1] == $cpid) continue;
		
			$msg = $arrT[count($arrT)-1];
			$arrV['msg'] = $msg;
			$arrV['pid'] = $arrT[1];
			$arrProcess[$msg] = $arrV;
		}

		return $arrProcess;
	}
	
	/**
	 * 暂停当前消息处理
	 */
	public function hup(){
		
		$this->writeProcess();
		$this->msg_hup();
		$this->writeLog($this->currMsg."  hang",$this->monitor,false);
		exit;
	}
	
	
	/**
	 * 暂停消息相关消息文件处理
	 */
	public function msg_hup(){
		
		$this->deleteMsg($this->processingfile, $this->currMsg);
		$this->LockAppend($this->hangfile, $this->currMsg);

	}
	
	/**
	 * 停止当前消息处理
	 */
	public function stop(){
		$this->msg_stop();
		$this->endProcess();
		$this->writeLog($this->currMsg."   stop",$this->monitor,false);
	}
	
	/**
	 * 停止消息相关消息文件处理
	 */
	public function msg_stop(){
		$this->deleteMsg($this->processingfile, $this->currMsg);
		$this->LockAppend($this->completefile, $this->currMsg);
	}
	
	
	/**
	 * 消息暂停信号处理函数
	 */
	public function sig_hup_handler(){
		
		if($this->isend) return ;
		if($this->currProcess === self::PROCESS_END){
			return;
		}
		$this->hup();
		$this->isend = true;
		self::exit_success('hup');
	}
	
	/**
	 * 尝试重新启动已暂停处理的消息
	 */
	public function restart(){
		
		$processfile = $this->getProcessFile();
		if(file_exists($processfile)){
			$pos = file_get_contents($processfile);
			if($pos != self::PROCESS_END){      
				$this->msg_restart();
				$this->writeLog($this->currMsg."     restart",$this->monitor,false);
				$this->process($this->currMsg,$pos);
				return true;
			}
		}
		return false;
	}
	
	
	/**
	 * 重新启动消息相关消息文件处理
	 */
	public function msg_restart(){
		
        $this->deleteMsg($this->hangfile, $this->currMsg);
		$this->LockAppend($this->processingfile, $this->currMsg);
			
	}
	

	/**
	 * 获得当前处理消息对应的进度文件
	 */
	public function getProcessFile(){
		
		return $this->processdir.'/process_'.$this->currMsg;
	}
	

	/**
	 * 将当前消息处理的进度写入进度文件
	 */
	public function writeProcess(){
		
		$file = $this->getProcessFile();
		if($this->currProcess !==NULL){
			$this->lockWrite($file, $this->currProcess);
		}
		
	}
	
	
	/**
	 * 获得当前正在处理的消息
	 */
	public function getCurrMsg(){
		return $this->currMsg;
	}
	
	
	/**
	 * 设置消息处理进度,一般由用户代码调用
	 */
	public function recordProcess($pos){
		
		$this->currProcess = $pos;
	}
	
	
	/**
	 * 获得下一个要处理的消息，首先处理之前中止的消息，然后尝试从原始消息文件读取消息
	 * 原始消息文件也没有，尝试生产新的消息
	 */
	public function getNextMsg(){
		
	    if(!$this->isneemsg) {
	        return self::$count++;
	    }
	    
		if(file_exists($this->hangfile)) {
			$arrHang =  file($this->hangfile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			if(!empty($arrHang)){
				$this->deleteMsg($this->hangfile, $arrHang[0]);  
				return $arrHang[0];
			}
		}
		
		$arrMsg = array();
		if(file_exists($this->msgqueuefile)){
		  $arrMsg = file($this->msgqueuefile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		}
		
		if(empty($arrMsg)) {
				$this->produce(); 
		}
		
		if(file_exists($this->msgqueuefile)){
		 $arrMsg = file($this->msgqueuefile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		}

		if(empty($arrMsg) || $arrMsg[0] == self::MSG_END) return NULL;
		
		 $this->deleteMsg($this->msgqueuefile, $arrMsg[0]);
		 return $arrMsg[0];
		
	}
	
	/**
	 * 启动下一个进程处理新的消息
	 * @param string $msg 消息
	 */
	public function startNextProcess($msg){
		
		$scriptPath = $GLOBALS['argv'][0];
		$cmd = "nohup /usr/local/bin/php {$scriptPath} msg {$msg}  >> /dev/null  2>&1 &";
		//echo $cmd.PHP_EOL;
		$this->writeLog($msg." start",$this->monitor,false);
		exec($cmd);
		
	}

	/**
	 * 添加新的消息
	 * @param  $msg 消息内容
	 */
	public function addMsg($msg){
		$this->LockAppend($this->msgqueuefile, $msg);
	}
	
	/**
	 * 标记所有消息已处理完毕
	 */
	public function endMsg(){
		$this->LockAppend($this->msgqueuefile, self::MSG_END,FILE_APPEND);
	}
	
	
    /**
     * 检查消息是否全都处理完毕
     */
    public function chechMsgEnd(){
    	
        if(!file_exists($this->msgqueuefile)) return false;
            	
    	$arrMsg = file($this->msgqueuefile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    	if($arrMsg[count($arrMsg)-1] == self::MSG_END) {
    		return true;
    	}
    	return false;
    }
    
    
    /**
     * 标记某一个消息已处理完毕
     */
    public function endProcess(){
    	$processFile = $this->getProcessFile();
    	$this->LockWrite($processFile, self::PROCESS_END);
    }
    
    
    /**
     * 从消息文件删除消息
     * @param string  $filename 文件名
     * @param string  $msg 消息内容
     */
    public function deleteMsg($filename,$msg){
    	
        if(!$this->isneemsg) return true;
        
    	if($this->lock()){
    		$arrMsg = file($filename,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    		foreach ($arrMsg as $key => $val){
    			if($val == $msg){
    				unset($arrMsg[$key]);
    				break;
    			}
    		}
    		$this->LockWrite($filename, implode("\n", $arrMsg)."\n");
    		$this->unLock();
    	}
    }
    
    public function  setIsNeedMsg($isneedmsg = true){ 
        $this->isneemsg =  $isneedmsg;
    }
    
    public function setFixNum($fixnum){
        $this->fixpronum = $fixnum;
    }
    
    public function LockWrite($filename,$msg){
    	file_put_contents($filename, $msg,LOCK_EX);
    }
    
    public function LockAppend($filename,$msg){
    	file_put_contents($filename, $msg."\n",FILE_APPEND | LOCK_EX);
    }
    
    public function lock(){
    	if($this->lockh == NULL){
     	  if(($this->lockh = fopen('/tmp/lock.txt', 'w+')) === false){
     	  	  self::exit_fail('open lock file fail');
     	  }
    	}
    	return flock($this->lockh, LOCK_EX);
    }
    
    public function unLock(){
    	return  flock($this->lockh, LOCK_UN);
    }
    
    
    public function exit_success($msg = ''){
        $this->isend = true;
        $this->writeLog($this->currMsg." exit  {$msg}",$this->monitor,false);
        exit();
    }
    
    public function exit_fail($msg = ''){
        $this->isend = true;
        $this->writeLog($this->currMsg." exit,some error: {$msg}",$this->monitor,false);
        exit();
    }
    
    
    /**
     * 日志打印
     * @param string $msg 当前正在处理的消息
     * @param string $type  要写入的文件名
     * @param string $split   是否不同消息打印的日志是分开的
     */
    public function writeLog($msg,$type = 'process',$split = true){
	
		$msg = '['.date('Y-m-d H:i:s',time()).']'. $msg;
		if($split){
		  $file = $this->processlogdir.'/'.$type.'.log'.$this->currMsg.'_'.date('Ymd',time());
		}else{
		  $file = $this->processlogdir.'/'.$type.'.log'.'_'.date('Ymd',time());
		}
		$file = getcwd().'/'.$file;
		$this->LockAppend($file, $msg);
    }
}
