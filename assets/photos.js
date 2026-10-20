'use strict';
// Convert locally, one photo at a time. The server still validates and re-encodes every upload.
window.QuackpackPhotos = {
  budget(limits = {}, count = 1) {
    const file = Number(limits?.file_bytes) || Infinity, post = Number(limits?.post_bytes) || Infinity;
    const bytes = Math.floor(Math.min(1800000, file - 16384, (post - 262144) / Math.max(1, count)));
    if (bytes < 65536) throw new Error('The server upload limit is too small for photos. Increase upload_max_filesize and post_max_size.');
    return bytes;
  },
  async prepare(file, maxBytes = 1800000) {
    if (!file || !file.size) throw new Error('This photo is empty. Choose it again.');
    if (file.size > 50 * 1024 * 1024) throw new Error('Choose a photo under 50 MB.');
    const heic = /\.(heic|heif)$/i.test(file.name || '') || /^image\/hei[cf]$/i.test(file.type);
    if (!/^image\/(jpeg|png|webp|heic|heif)$/i.test(file.type || '') && !/\.(jpe?g|png|webp|heic|heif)$/i.test(file.name || ''))
      throw new Error('Choose a JPEG, PNG, WebP, or iPhone HEIC photo.');
    const url = URL.createObjectURL(file), image = new Image(), canvas = document.createElement('canvas');
    try {
      await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('This photo took too long to open. Choose it again.')), 30000);
        image.onload = () => { clearTimeout(timer); resolve(); };
        image.onerror = () => { clearTimeout(timer); reject(new Error(heic
          ? 'This browser cannot open this HEIC photo. Use Safari 17 or newer, or export it as JPEG from Photos.'
          : 'This photo could not be opened. Choose it again or export it as JPEG.')); };
        image.src = url;
      });
      const width = image.naturalWidth, height = image.naturalHeight;
      if (!width || !height) throw new Error('This photo has no readable image dimensions.');
      let edge = Math.min(2560, Math.max(width, height));
      for (let attempt = 0; attempt < 4; attempt++, edge = Math.floor(edge * 0.75)) {
        const scale = Math.min(1, edge / Math.max(width, height));
        canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('The browser could not prepare this photo. Close other tabs and try again.');
        context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        for (const quality of [0.9, 0.78, 0.65]) {
          const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
          if (!blob) throw new Error('The browser could not convert this photo. Try a smaller photo.');
          if (blob.type !== 'image/jpeg') throw new Error('The browser did not create a JPEG photo.');
          if (blob.size <= maxBytes) return new File([blob], (file.name || 'photo').replace(/\.[^.]*$/, '') + '.jpg', {type:'image/jpeg', lastModified:file.lastModified});
        }
      }
      throw new Error('This photo could not fit the server upload limit. Try a smaller photo.');
    } finally {
      image.onload = image.onerror = null; image.src = ''; URL.revokeObjectURL(url);
      // Release the canvas backing store promptly on iOS.
      canvas.width = canvas.height = 1;
    }
  }
};
