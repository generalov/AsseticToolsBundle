var gulp = require('gulp'),
    globby = require('globby'),
    minimist = require('minimist'),
    net = require('net'),
    newer = require('gulp-newer'),
    watch = require('gulp-chokidar')(gulp),
    forever = require('forever-monitor'),
    browserSync = require("browser-sync").create(),
    tmp = require('tmp');


var defaultOptions = {
        string: ['env', 'server'],
        default: {
            env: process.env.NODE_ENV || 'production',
            server: '127.0.0.1:8080'
        }
    },
    asseticRoots = ['app/Resources/public', 'src/*/*/Resources/public', 'web/bower'],
    asseticFiles = ['{root}/**/*.css', '{root}/**/*.js', '{root}/**/*.scss', '!{root}/**/*scsslint_tmp*.scss'],
    asseticSocketOpts = {mode: 0600, prefix: 'assetic-dump-files', postfix: '.sock'};


var options = minimist(process.argv.slice(2), defaultOptions),
    server = options.server,
    asseticFiles = globby.sync(asseticRoots).reduce(function (a, f) {
            asseticFiles.forEach(function (pattern) {
                a.push(pattern.replace(/\{root\}/, f));
            });
            return a;
        },
        []),
    asseticSocket = tmp.tmpNameSync(asseticSocketOpts);

tmp.setGracefulCleanup();


gulp.task('symfony-assetic-dump-files', function () {
    var child = forever.start([
        'php app/console assetic:dump-files --force --listen=' + asseticSocket
    ], {});
});


gulp.task('symfony-server', function () {
    var child = forever.start(['php', 'app/console', 'server:run', server], {});
});


gulp.task('watch', ['symfony-assetic-dump-files'], function () {
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
            target: server,
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
