var gulp = require('gulp'),
    globby = require('globby'),
    net = require('net'),
    newer = require('gulp-newer'),
    watch = require('gulp-chokidar')(gulp),
    forever = require('forever-monitor'),
    browserSync = require("browser-sync").create(),
    tmp = require('tmp');

var backendHost="127.0.0.1:8080";
var asseticFiles = globby.sync(['app/Resources/public', 'src/*/*/Resources/public']).reduce(function (a, f) {
    /* common */
    a.push(f + '/**/*.css');
    a.push(f + '/**/*.js');

    /* scss */
    a.push(f + '/**/*.scss');
    a.push('!' + f + '/**/*scsslint_tmp*.scss');
    return a;
}, []);

var asseticSocket = tmp.tmpNameSync({ mode: 0600, prefix: 'assetic-dump-files', postfix: '.sock' });
tmp.setGracefulCleanup();

gulp.task('watch', ['assetic-dump-files'], function () {
    watch(asseticFiles, {root: 'src'}).on('change', onChange);
    watch(asseticFiles, {root: 'src'}).on('delete', onUpdate);
    watch(asseticFiles, {root: 'src'}).on('add', onUpdate);

    function send(msg) {
        var c = net.createConnection(asseticSocket);
        c.end(msg + '\n');
    }

    function onUpdate(filename) {
        send('refresh\n' + filename.replace(/^src\//, ''));
    }

    function onChange(filename) {
        send(filename.replace(/^src\//, ''));
    }

});


gulp.task('assetic-dump-files', function () {
    var child = forever.start([
        'php app/console assetic:dump-files --force --listen=' + asseticSocket
    ], {});
});


gulp.task('symfony-server', function () {
    var child = forever.start(['php', 'app/console', 'server:run', backendHost], {});
});


gulp.task('bs', function () {
    browserSync.init({
        baseDir: 'web/',
        files: [
            'web/{css,assetic}/**/*.css',
            'web/{js,assetic}/**/*.js',
            'app/Resources/views/**/*.twig',
            'src/*/*/Resources/views/**/*.twig'
        ],
        //logLevel: "debug",
        reloadOnRestart: true,
        online: false,
        proxy: {
            target: backendHost,
            xfwd: true,
            reqHeaders: function (config) {
                return {
                    // prevent 'Host' header overriding with proxies target
                    //"host":            config.urlObj.host,
                    "accept-encoding": "identity", // disable any compression
                    "agent": false
                };
            }
        },
        open: false
    });
});


gulp.task('serve', ['symfony-server', 'bs', 'watch']);

