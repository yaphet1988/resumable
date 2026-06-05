# resumable
文件分片断点续传demo

## 前端
前端主要使用**resumable.js**来实现文件的切片并行上传(分片失败自动重试)
> https://www.npmjs.com/package/resumablejs
使用示例前端代码见文件**upload_demo.html**

## 后端
后端以PHP为例（其他语言也可以按其实现原理替换一下，AI一下）
文件**Resumable.php**封装好了对应的服务端的实现
具体使用方式见如下：
``` PHP
$resumable = new Resumable();
$resumable->tempFolder   = $tmpDir;  // 临时文件夹，用来暂时存放上传上来的分片文件，合成后会删除
$resumable->uploadFolder = $completeDir;  // 最终目标文件夹，从分片合成

// 实现托管给Resumable类
$status = $resumable->process();

if (!$resumable->isUploadComplete()) {
    if (!$status) {
        return response('', 404);
    } else {
        return response('', $status);
    }
}
return response('文件上传成功', 200),
```
