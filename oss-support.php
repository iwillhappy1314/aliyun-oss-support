<?php
/**
 * Plugin Name: 阿里云附件
 * Plugin URI: "http://mawenjian.net/p/977.html"
 * Description: 使用阿里云存储OSS作为附件存储空间。This is a plugin that used Aliyun Cloud Storage(Aliyun OSS) for attachments remote saving.
 * Author: 马文建(Wenjian Ma) & ichou
 * Version: 1.1
 * Author URI: http://mawenjian.net/
 */


/**
 * iChou升级说明
 * Update URI: http://ichou.cn/posts/ji-yu-a-li-yun-ossde-wordpressyuan-cheng-fu-jian-zhi-chi-cha-jian-a-li-yun-fu-jian-aliyun-support-xiu-ding-ban
 * Author: Ivan Chou (ichou.cn)
 *
 * 1.升级 ali-OSS-SDK 到 1.1.6 版本
 * 2.支持给 OSS 绑定的独立域名
 * 3.支持自定 OSS 上文件存放目录 （不影响本地存储，中途若修改请手动移动 OSS 上文件，否则可能链接不到之前的资源）
 * 4.修正原插件 bug 若干
 * 5.优化代码 （移除所有 Notice 级报错）
 *
 */

require_once('sdk.class.php');
if ( !defined('WP_PLUGIN_URL') )
	define( 'WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins' );

//  plugin url
define('OSS_BASENAME', plugin_basename(__FILE__));
define('OSS_BASEFOLDER', plugin_basename(dirname(__FILE__)));
define('OSS_FILENAME', str_replace(OSS_BASEFOLDER.'/', '', plugin_basename(__FILE__)));

// 初始化
register_activation_hook(__FILE__, 'oss_set_options');

// 初始化选项
function oss_set_options() {
    $options = array(
        'bucket' => "",
        'ak' => "",
    	'sk' => "",
        'path' => "",
        'cname' => "",
    );
    
    add_option('oss_options', $options, '', 'yes');
}


function oss_admin_warnings() {
    $oss_options = get_option('oss_options');

    $oss_bucket = isset($oss_options['bucket']) ? esc_attr($oss_options['bucket']) : null;
	if ( !$oss_bucket && !isset($_POST['submit']) ) {
		function oss_warning() {
			echo "<div id='oss-warning' class='updated fade'><p><strong>".__('OSS is almost ready.')."</strong> ".sprintf(__('You must <a href="%1$s">enter your OSS Bucket </a> for it to work.'), "options-general.php?page=" . OSS_BASEFOLDER . "/oss-support.php")."</p></div>";
		}
		add_action('admin_notices', 'oss_warning');
		return;
	} 
}
oss_admin_warnings();

//上传函数
function _file_upload( $object , $file , $opt = array()){
		
	//获取WP配置信息
	$oss_options = get_option('oss_options', TRUE);
    $oss_bucket = esc_attr($oss_options['bucket']);
	$oss_ak = esc_attr($oss_options['ak']);
	$oss_sk = esc_attr($oss_options['sk']);

    if ($oss_options['path'] != "")
        $object = $oss_options['path'].$object;

	//实例化存储对象
	if(!is_object($aliyun_oss))
		$aliyun_oss = new ALIOSS($oss_ak, $oss_sk);
	//上传原始文件
	$opt['Expires'] = 'access plus 1 years';
	$aliyun_oss->upload_file_by_file( $oss_bucket, $object, $file, $opt );

	return TRUE;
}

//删除本地文件
function _delete_local_file($file){
	try{
	  //文件不存在
		if(!@file_exists($file))
			return TRUE;
		//删除文件
		if(!@unlink($file))
			return FALSE;
		return TRUE;
	}
	catch(Exception $ex){
		return FALSE;
	}
}

/**
 * 上传所有文件到服务器，没有删除本地文件
*  @static
 * @param $metadata from function wp_generate_attachment_metadata
 * @return array
 */
function upload_images($metadata)
{	
	//获取上传路径
	$wp_upload_dir = wp_upload_dir();
	$oss_options = get_option('oss_options', TRUE);
	$oss_nolocalsaving = (esc_attr($oss_options['nolocalsaving'])=='true') ? true : false;

	$upload_path = get_option('upload_path');
	if($upload_path == '.' ){
		$upload_path = '';
		$object = $metadata['file'];
	}
	else{
		$upload_path = trim($upload_path,'/');
		$object = ltrim($upload_path.'/'.$metadata['file'],'/');
	}
	
	//上传原始文件
	$file = $wp_upload_dir['basedir'].'/'.$metadata['file'];
	_file_upload ( $object, $file);
	
	//如果不在本地保存，则删除
	if($oss_nolocalsaving)
		_delete_local_file($file);

	//上传小尺寸文件
	if (isset($metadata['sizes']) && count($metadata['sizes']) > 0)
	{
		//there may be duplicated filenames,so ....
		foreach ($metadata['sizes'] as $val)
		{
			$object = ltrim( $upload_path.$wp_upload_dir['subdir'].'/'.$val['file'] , '/' );
			$file = $wp_upload_dir['path'].'/'.$val['file'];
			$opt =array('Content-Type' => $val['mime-type']);
			_file_upload ( $object, $file, $opt );
			
			//如果不在本地保存，则删除
			if($oss_nolocalsaving)
				_delete_local_file($file);

		}
	}
	return $metadata;
}

/**
 * 删除远程服务器上的单个文件
 * @static
 * @param $file
 * @return void
 */
function delete_remote_file($file)
{		
	//获取WP配置信息
	$oss_options = get_option('oss_options', TRUE);
    $oss_bucket = esc_attr($oss_options['bucket']);
	$oss_ak = esc_attr($oss_options['ak']);
	$oss_sk = esc_attr($oss_options['sk']);
	
	//获取保存路径
	$upload_path = get_option('upload_path');
	if($upload_path == '.' )
		$upload_path='';
	else
		$upload_path = trim($upload_path,'/');
	
	//获取上传路径
	$wp_upload_dir = wp_upload_dir();
	
	$del_file = str_replace($wp_upload_dir['basedir'],'',$file);
	$del_file = ltrim( str_replace('./','',$del_file), '/');
	if( $upload_path != '' )
		$del_file = $upload_path .'/'. $del_file;

    if ($oss_options['path'] != "")
        $del_file = $oss_options['path'].$del_file;
	
	//实例化存储对象
	if(!is_object($aliyun_oss))
		$aliyun_oss = new ALIOSS($oss_ak, $oss_sk);
	//删除文件
	$aliyun_oss->delete_object( $oss_bucket, $del_file);

	return $file;
}

//生成缩略图后立即上传生成的文件
add_filter('wp_generate_attachment_metadata', 'upload_images', 999);
//删除远程附件
add_action('wp_delete_file', 'delete_remote_file');

function oss_plugin_action_links( $links, $file ) {
	if ( $file == plugin_basename( dirname(__FILE__).'/oss-support.php' ) ) {
		$links[] = '<a href="options-general.php?page=' . OSS_BASEFOLDER . '/oss-support.php">'.__('Settings').'</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links', 'oss_plugin_action_links', 10, 2 );

function oss_add_setting_page() {
    add_options_page('OSS Setting', 'OSS Setting', 'manage_options', __FILE__, 'oss_setting_page');
}

add_action('admin_menu', 'oss_add_setting_page');

//设置 upload_url_path 地址     by:ichou 08-27-2014
function oss_setting_url( $uploads ) {
    $oss_options = get_option('oss_options', TRUE);

    if ($oss_options['cname'] != "") {
        $baseurl = rtrim($oss_options['cname'], '/') .'/'. rtrim($oss_options['path'], '/');
        $uploads['baseurl'] = $baseurl;
        return $uploads;
    }
}

add_filter( 'upload_dir', 'oss_setting_url' );

function oss_setting_page() {

	$options = array();
	if(isset($_POST['bucket'])) {
		$options['bucket'] = trim(stripslashes($_POST['bucket']));
	}
	if(isset($_POST['ak'])) {
		$options['ak'] = trim(stripslashes($_POST['ak']));
	}
	if(isset($_POST['sk'])) {
		$options['sk'] = trim(stripslashes($_POST['sk']));
	}
    if(isset($_POST['path'])) {
        $options['path'] = rtrim(trim(stripslashes($_POST['path'])), '/').'/';
    }
    if(isset($_POST['cname'])) {
        $options['cname'] = trim(stripslashes($_POST['cname']));
    }
    if(isset($_POST['nolocalsaving'])) {
        $options['nolocalsaving'] = 'true';
    }

	if($options !== array() ){
	
		update_option('oss_options', $options);
        
?>
<div class="updated"><p><strong>设置已保存！</strong></p></div>
<?php
    }

    $oss_options = get_option('oss_options');

    $oss_bucket = isset($oss_options['bucket']) ? esc_attr($oss_options['bucket']) : null;
    $oss_ak = isset($oss_options['ak']) ? esc_attr($oss_options['ak']) : null;
    $oss_sk = isset($oss_options['sk']) ? esc_attr($oss_options['sk']) : null;
    $oss_path = isset($oss_options['path']) ? esc_attr($oss_options['path']) : null;
    $oss_cname = isset($oss_options['cname']) ? esc_attr($oss_options['cname']) : null;
	
	$oss_nolocalsaving = isset($oss_options['nolocalsaving']) ? esc_attr($oss_options['nolocalsaving']) : null;
	($oss_nolocalsaving == 'true') ? ($oss_nolocalsaving = true) : ($oss_nolocalsaving = false);
?>
<div class="wrap" style="margin: 10px;">
    <h2>阿里云存储 设置</h2>
    <form name="form1" method="post" action="<?php echo wp_nonce_url('./options-general.php?page=' . OSS_BASEFOLDER . '/oss-support.php'); ?>">
        <fieldset>
            <legend>Bucket 设置</legend>
            <input type="text" name="bucket" value="<?php echo $oss_bucket;?>" placeholder="请输入云存储使用的 bucket"/>
            <p>请先访问 <a href="http://i.aliyun.com/dashboard?type=oss">阿里云存储</a> 创建 bucket 后，填写以上内容。</p>
        </fieldset>
        <fieldset>
            <legend>Access Key / API key</legend>
            <input type="text" name="ak" value="<?php echo $oss_ak;?>" placeholder=""/>
            <p>访问 <a href="http://i.aliyun.com/access_key/" target="_blank">阿里云 密钥管理页面</a>，获取 AKSK</p>
        </fieldset>
        <fieldset>
            <legend>Secret Key</legend>
            <input type="text" name="sk" value="<?php echo $oss_sk;?>" placeholder=""/>
            <P> </P>
        </fieldset>
        <fieldset>
            <legend>Save path on OSS</legend>
            <input type="text" name="path" value="<?php echo $oss_path;?>" placeholder=""/>
            <P></P>
        </fieldset>
        <fieldset>
            <legend>OSS-Url</legend>
            <input type="text" name="cname" value="<?php echo $oss_cname;?>" placeholder=""/>
            <P>OSS 的可访问URL 支持已绑定到 OSS 的独立域名</P>
        </fieldset>
        <fieldset>
            <legend>不在本地保留备份：</legend>
            <input type="checkbox" name="nolocalsaving" <?php if($oss_nolocalsaving) echo 'checked="TRUE"';?> />
        </fieldset>
        <fieldset class="submit">
            <legend>更新选项</legend>
            <input type="submit" name="submit" value="更新" />
        </fieldset>
    </form>
</div>
<?php
}