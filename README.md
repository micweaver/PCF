PCF
===

php并发框架，让开发的脚本自动并发运行起来



	PCF主要有两个目的:
	1.使并发程序写起来更容易。写的单个脚本可以自动并发跑起来
	2.使并发控制更简单。并发的进程数可以随时增多和减少，暂停的程序会记录暂停点，下次执行时从暂停点继续执行。



PCF的框架图如下，具体解释见 http://blog.csdn.net/micweaver/article/details/18143737
![github](http://img.blog.csdn.net/20140111171633609 "github")

假设我们有如下的数据处理需求: 一共有200个表的数据，每个表有一百万的数据，对于每个数据要进行某种处理。

为了高效处理，需要进行并发处理，用户代码示例如下:

	<?php  

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
