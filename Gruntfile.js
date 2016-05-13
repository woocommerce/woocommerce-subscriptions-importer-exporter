var semver = require('semver'),
    exec = require('exec');

module.exports = function(grunt) {
	'use strict';

	var version = grunt.file.readJSON('package.json').version;

	// plugin configurations
	grunt.initConfig({
		pkg: grunt.file.readJSON('package.json'),
		// Ref: https://github.com/SaschaGalley/grunt-phpunit
		phpunit: {
			classes: {},
			options: {
				failOnFailures: true //counter intuitive but -> Phpunit will return with exit code 1 when there are failed tests and with exit code 2 when there are errors, and grunt will abort in these cases. When you want the task finish without aborting set failOnFailures to true.
			}
		},
		// Ref: https://github.com/SaschaGalley/grunt-phpcs
		phpcs: {
			application: {
				// directories to run through PHP_CodeSniffer - testing all php files
				dir: ['*.php','*/**/*.php']
			},
			options: {
				bin: 'tmp/php-codesniffer/scripts/phpcs',
				verbose: true,
				showSniffCodes: true,
				warningSeverity: 0,
				standard: 'Prospress',
				ignore: '*/tmp/*,*/tests/*,*/node_modules/*,*/libraries/*,*/woo-includes/*',
				ignoreExitCode: true // without this option, the errors picked up from the phpcs make grunt exit, even running --force is not letting us bypass this.
			}
		},
		// Ref: https://github.com/dylang/grunt-prompt
		prompt: {
			update: {
				options: {
					questions: [{
						config: 'version.update',
						type: 'list',
						message: 'Bump version from ' + version + ' to: (User arrow keys)',
						default: 'patch',
						choices: [
							{ value: 'build', name: 'Build: ' + version + '-? for release' },
							{ value: 'patch', name: 'Patch: ' + semver.inc( version, 'patch' ) + ' for compatibility and bug fixes' },
							{ value: 'minor', name: 'Minor: ' + semver.inc( version, 'minor' ) + ' for added functionality' },
							{ value: 'major', name: 'Major: ' + semver.inc( version, 'major' ) + ' <%= version %> for major content releases' }
						]
					}],
				}
			}
		},
		makepot: {
			options: {
				type: 'wp-plugin',
				domainPath: 'languages',
				potHeaders: {
					'report-msgid-bugs-to': 'https://github.com/Prospress/woocommerce-subscriptions-gifting/issues',
					'language-team': 'Gabor Javorszky <translations@prospress.com>',
					'language': 'en_US'
				}
			},
			frontend: {
				options: {
					potFilename: 'woocommerce-subscriptions-gifting.pot',
					exclude: [
						'.tx/.*',
						'tests/.*',
						'woo-includes/.*',
						'includes/libraries/*',
						'node_modules',
						'tmp'
					]
				}
			}
		},
		checktextdomain: {
			options:{
				text_domain: 'woocommerce-subscriptions-gifting',
				keywords: [
					'__:1,2d',
					'_e:1,2d',
					'_x:1,2c,3d',
					'esc_html__:1,2d',
					'esc_html_e:1,2d',
					'esc_html_x:1,2c,3d',
					'esc_attr__:1,2d',
					'esc_attr_e:1,2d',
					'esc_attr_x:1,2c,3d',
					'_ex:1,2c,3d',
					'_n:1,2,4d',
					'_nx:1,2,4c,5d',
					'_n_noop:1,2,3d',
					'_nx_noop:1,2,3c,4d'
				]
			},
			files: {
				src:  [
					'**/*.php', // Include all files
					'!includes/libraries/**', // Exclude libraries
					'!woo-includes/**', // Exclude woo-includes/
					'!node_modules/**', // Exclude node_modules/
					'!tmp/**', // Exclude tmp/
					'!tests/**', // Exclude tests/
					'!.tx/**' // Exclude .tx/
				],
				expand: true
			}
		},

		potomo: {
			dist: {
				options: {
					poDel: false
				},
				files: [{
					expand: true,
					cwd: 'languages',
					src: ['*.po'],
					dest: 'languages',
					ext: '.mo',
					nonull: false
				}]
			}
		}
	});

	// Set the default grunt command to run test cases
	grunt.registerTask('default', ['test']);

	/**
	 * Grunt Test
	 *
	 * Create the test task and run phpunit and phpcs
	 */
	grunt.registerTask('test', ['phpunit', 'phpcs', 'checktextdomain']);

	/**
	 * Grunt Build
	 *
	 * Run Test (PhpUnit, PHP_CodeSniffer), Create the pot file
	 * Check/Set Version Numbers and Tag the release in git.
	 *
	 * Currently the build is being processed despite the standards still having errors in test.
	 * I think it would be cool if a message in terminal would appear asking whether to continue with the build
	 * task if there's any failing tests or standards erros ( Tamara had this when deploying aswell ).
	 */
	grunt.registerTask('buildTask', 'TODO: Description.', function() {
		// Require tests to run without errors before building
		// This can be ignored with the --force tag i.e. grunt --force build
		grunt.task.requires('test');
		var pkg = grunt.file.readJSON('package.json');

		// TODO: Set the version number in package.json,
		grunt.task.run( 'prompt:update' );
		//semver.inc('1.2.3', 'patch');
		console.log( 'version = ' + pkg.version );

		// TODO: Tag for release in git
		console.log( 'Completed build task' );
	});
	// Build Custom Task which can take the new version number
	grunt.registerTask('build', 'The "build" sequence: [Test -> Build]', function() {
		grunt.task.run(['test', 'buildTask']);
	});

	/**
	 * Grunt Deploy
	 *
	 * Run build, copy public files to /woothemes/ repo (i.e. not tests, grunt files etc.),
	 * tag version in /woothemes repo and move Trello card to Deploy Update queue.
	 *
	 */
	grunt.registerTask('deployTask', 'TODO: Add Description', function() {
		// ensure the build task is run first - should exit deploy if the build fails unless --force is used.
		grunt.task.requires('build');

		// "Get off my lawn!" - Clint Eastwood.. deployed yall.
		console.log( 'Completed deploy task.');
	});
	grunt.registerTask('deploy', 'The "deploy" sequence: [Test -> Build -> Deploy]', function() {
		grunt.task.run(['build', 'deployTask']);
	});

	/**
	 * Run i18n related tasks. This includes extracting translatable strings, uploading the master
	 * pot file, downloading any and all 100% complete po files, converting those to mo files.
	 * If this is part of a deploy process, it should come before zipping everything up
	 */
	grunt.registerTask( 'i18n', [
		'checktextdomain',
		'makepot',
		'potomo'
	]);

	// Load phpunit plugin
	grunt.loadNpmTasks('grunt-phpunit');
	// Load phpCodeSniffer plugin
	grunt.loadNpmTasks('grunt-phpcs');
	// Load prompt plugin
	grunt.loadNpmTasks('grunt-prompt');
	// Load bump plugin
	grunt.loadNpmTasks('grunt-bump');
	// Load semver plugin
	grunt.loadNpmTasks('grunt-semver');
	// Load exec plugin
	grunt.loadNpmTasks('grunt-exec');
	// Load the i18n plugin to extract translatable strings
	grunt.loadNpmTasks( 'grunt-wp-i18n' );
	// Load checktextdomain
	grunt.loadNpmTasks( 'grunt-checktextdomain' );
	// Load potomo plugin
	grunt.loadNpmTasks( 'grunt-potomo' );
};
