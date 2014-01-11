<?php  
/**
 * @author		lizhonghua
 * @desc   PCF 框架 用户代码
 */

require_once 'currFrame.php';//包含框架代码

define('TABLENUM',10);

//用户类继承框架类
class Example extends CurrFrame { 
	/**
	 * 产生消息
	 */
	public function produce(){
		
		for ($i = 0;$i <TABLENUM ;$i++){
			$this->addMsg($i);//添加消息，此处为表序号
		}
		$this->endMsg(); //结束消息，如果没有更多消息，就要结束，否则总会尝试读取更多消息
	}
	
	/**
	 * 具体处理代码
	 * @param  $msg 当前处理的消息
	 * @param  $pos  当前处理到的位置  
	 */
	public function process($msg,$pos = NULL) {
		
		$no = $msg;
	    $j = $pos ==NULL ? 0:$pos;
	   
		for(;$j<10;$j++){
		    //此处放具体处理逻辑
		    
			$this->recordProcess($j); //记录处理进度
		}
	}
	
	/**
	 *所有进程运行完成后执行的代码
	 */
	public function output(){
		
	}
}

$obj = new Example();
$obj->run();







