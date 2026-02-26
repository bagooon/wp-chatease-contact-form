// scripts/build-plugin-zip.mjs
import path from 'node:path'
import fs from 'node:fs'
import fsExtra from 'fs-extra'
import archiver from 'archiver'

async function main() {
  // ★ ここだけ自分のプラグイン名に合わせて変更
  const PLUGIN_SLUG = 'chatease-contact-form'

  const rootDir = process.cwd()
  const srcDir = path.join(rootDir, 'src')
  const distDir = path.join(rootDir, 'dist')
  const buildDir = path.join(distDir, PLUGIN_SLUG)

  // ディレクトリ存在チェック
  if (!fs.existsSync(srcDir)) {
    console.error(`src ディレクトリが見つかりません: ${srcDir}`)
    process.exit(1)
  }

  // dist を作り直し
  await fsExtra.remove(distDir)
  await fsExtra.ensureDir(distDir)

  // ビルド用ディレクトリ作成
  await fsExtra.ensureDir(buildDir)

  // src の中身を buildDir にコピー（開発用ファイルは除外）
  await fsExtra.copy(srcDir, buildDir, {
    filter: (src) => {
      const rel = path.relative(srcDir, src)
      // src 直下からの相対パスで判定
      const excluded = [
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'node_modules',
        '.git',
        '.github',
        'tests',
        'phpstan.neon',
        'phpunit.xml',
      ]

      // ルートからの相対パスの先頭が excluded と一致したら除外
      return !excluded.some((name) => rel === name || rel.startsWith(`${name}${path.sep}`))
    },
  })

  // zip ファイルのパス
  const zipPath = path.join(distDir, `${PLUGIN_SLUG}.zip`)

  // zip ストリーム作成
  const output = fs.createWriteStream(zipPath)
  const archive = archiver('zip', {
    zlib: { level: 9 },
  })

  output.on('close', () => {
    console.log(`ZIP 作成完了: ${zipPath} (${archive.pointer()} bytes)`)
  })

  archive.on('warning', (err) => {
    if (err.code === 'ENOENT') {
      console.warn('Warning:', err)
    } else {
      throw err
    }
  })

  archive.on('error', (err) => {
    throw err
  })

  archive.pipe(output)

  // dist/chatease-contact-form ディレクトリを、ZIP のルートに chatease-contact-form/ として追加
  archive.directory(buildDir, PLUGIN_SLUG)

  await archive.finalize()
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})