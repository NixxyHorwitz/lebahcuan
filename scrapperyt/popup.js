// popup.js - Controller logic for LebahCuan YT Scrapper Extension

document.addEventListener("DOMContentLoaded", () => {
  const scrapedCount = document.getElementById("scrapedCount");
  const scrapingToggle = document.getElementById("scrapingToggle");
  const statusLabel = document.getElementById("statusLabel");
  const previewBadge = document.getElementById("previewBadge");
  const previewTableBody = document.getElementById("previewTableBody");
  
  const toggleSettingsBtn = document.getElementById("toggleSettingsBtn");
  const settingsBody = document.getElementById("settingsBody");
  const settingsArrow = document.getElementById("settingsArrow");
  
  const rewardMinInput = document.getElementById("rewardMin");
  const rewardMaxInput = document.getElementById("rewardMax");
  const durationMinInput = document.getElementById("durationMin");
  const durationMaxInput = document.getElementById("durationMax");

  const copyJsonBtn = document.getElementById("copyJsonBtn");
  const downloadJsonBtn = document.getElementById("downloadJsonBtn");
  const clearBtn = document.getElementById("clearBtn");
  const toast = document.getElementById("toast");

  // Load saved state & settings
  function loadData() {
    chrome.storage.local.get(["scrapedVideos", "isScrapingActive", "scraperSettings"], (result) => {
      const videos = result.scrapedVideos || [];
      scrapedCount.textContent = videos.length;
      previewBadge.textContent = `${videos.length} Video`;

      renderPreviewTable(videos);

      const isActive = result.isScrapingActive !== undefined ? result.isScrapingActive : true;
      scrapingToggle.checked = isActive;
      updateStatusUI(isActive);

      const s = result.scraperSettings || {
        rewardMin: 50, rewardMax: 200, durationMin: 15, durationMax: 60
      };
      rewardMinInput.value = s.rewardMin || 50;
      rewardMaxInput.value = s.rewardMax || 200;
      durationMinInput.value = s.durationMin || 15;
      durationMaxInput.value = s.durationMax || 60;
    });
  }

  // Update Status UI
  function updateStatusUI(isActive) {
    if (isActive) {
      statusLabel.textContent = "Scraping Aktif";
      statusLabel.style.color = "#10b981";
    } else {
      statusLabel.textContent = "Scraping Dijeda";
      statusLabel.style.color = "#94a3b8";
    }
  }

  // Render Table
  function renderPreviewTable(videos) {
    previewTableBody.innerHTML = "";

    if (videos.length === 0) {
      const emptyRow = document.createElement("tr");
      emptyRow.className = "empty-row";
      emptyRow.innerHTML = `
        <td colspan="3">
          <div class="empty-state">
            <div style="font-size:24px;margin-bottom:6px;">🎬</div>
            <div>Belum ada video ter-scrape.</div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;">Buka YouTube dan scroll ke bawah!</div>
          </div>
        </td>`;
      previewTableBody.appendChild(emptyRow);
      return;
    }

    const recent = [...videos].reverse().slice(0, 30);
    recent.forEach(v => {
      const row = document.createElement("tr");

      const titleTd = document.createElement("td");
      titleTd.className = "video-title-cell";
      titleTd.textContent = v.title;
      titleTd.title = v.title;

      const idTd = document.createElement("td");
      idTd.className = "yt-id-cell";
      idTd.textContent = v.youtube_id || v.youtubeId || '-';

      const durTd = document.createElement("td");
      durTd.className = "yt-dur-cell";
      const dur = v.watch_duration || v.duration || 30;
      durTd.textContent = `${dur}s`;

      row.appendChild(titleTd);
      row.appendChild(idTd);
      row.appendChild(durTd);
      previewTableBody.appendChild(row);
    });
  }

  // Toggle Settings Panel
  toggleSettingsBtn.addEventListener("click", () => {
    const isShowing = settingsBody.classList.toggle("show");
    settingsArrow.textContent = isShowing ? "▲" : "▼";
  });

  // Save Settings on Input
  function saveSettings() {
    const settings = {
      rewardMin: parseFloat(rewardMinInput.value) || 50,
      rewardMax: parseFloat(rewardMaxInput.value) || 200,
      durationMin: parseInt(durationMinInput.value, 10) || 15,
      durationMax: parseInt(durationMaxInput.value, 10) || 60
    };
    chrome.storage.local.set({ scraperSettings: settings });
  }

  [rewardMinInput, rewardMaxInput, durationMinInput, durationMaxInput].forEach(inp => {
    inp.addEventListener("change", saveSettings);
  });

  // Toggle Scraping Active
  scrapingToggle.addEventListener("change", (e) => {
    const isActive = e.target.checked;
    chrome.storage.local.set({ isScrapingActive: isActive }, () => {
      updateStatusUI(isActive);
    });
  });

  // Message listener from content script
  chrome.runtime.onMessage.addListener((message) => {
    if (message.action === "updateVideoCount") {
      scrapedCount.textContent = message.count;
      previewBadge.textContent = `${message.count} Video`;
      chrome.storage.local.get(["scrapedVideos"], (res) => {
        renderPreviewTable(res.scrapedVideos || []);
      });
    }
  });

  // Toast Notification
  function showToast(msg) {
    const toastMsg = document.getElementById("toastMsg");
    toastMsg.textContent = msg;
    toast.classList.add("show");
    setTimeout(() => {
      toast.classList.remove("show");
    }, 2500);
  }

  // Build Clean JSON for LebahCuan
  function buildExportData(videos) {
    const rMin = Math.min(parseFloat(rewardMinInput.value) || 50, parseFloat(rewardMaxInput.value) || 200);
    const rMax = Math.max(parseFloat(rewardMinInput.value) || 50, parseFloat(rewardMaxInput.value) || 200);
    const dMin = Math.min(parseInt(durationMinInput.value, 10) || 15, parseInt(durationMaxInput.value, 10) || 60);
    const dMax = Math.max(parseInt(durationMinInput.value, 10) || 15, parseInt(durationMaxInput.value, 10) || 60);

    return videos.map((v, idx) => {
      const vid = v.youtube_id || v.youtubeId;
      // Randomize reward between min and max rounded to 10
      const randReward = Math.round((Math.random() * (rMax - rMin) + rMin) / 10) * 10;
      // Duration from scraped duration or random between dMin and dMax
      const scrapedDur = v.watch_duration || v.duration;
      const finalDur = (scrapedDur && scrapedDur >= 10 && scrapedDur <= 300) 
        ? scrapedDur 
        : Math.round(Math.random() * (dMax - dMin) + dMin);

      return {
        title: v.title,
        youtube_id: vid,
        reward_amount: randReward,
        watch_duration: finalDur,
        sort_order: idx + 1,
        url: `https://www.youtube.com/watch?v=${vid}`
      };
    });
  }

  // Copy JSON Button
  copyJsonBtn.addEventListener("click", () => {
    chrome.storage.local.get(["scrapedVideos"], (result) => {
      const videos = result.scrapedVideos || [];
      if (videos.length === 0) {
        alert("Belum ada video ter-scrape! Buka halaman YouTube dan scroll untuk mengumpulkan video.");
        return;
      }

      const exportData = buildExportData(videos);
      const jsonStr = JSON.stringify(exportData, null, 2);

      navigator.clipboard.writeText(jsonStr).then(() => {
        const origText = copyJsonBtn.innerHTML;
        copyJsonBtn.innerHTML = `<span>✓</span> Tersalin (${exportData.length} Video)!`;
        copyJsonBtn.style.background = "linear-gradient(135deg, #10b981, #059669)";

        showToast(`🎉 ${exportData.length} video siap di-paste ke LebahCuan!`);

        setTimeout(() => {
          copyJsonBtn.innerHTML = origText;
          copyJsonBtn.style.background = "";
        }, 2200);
      }).catch(err => {
        console.error("Gagal menyalin:", err);
        alert("Gagal menyalin ke clipboard. Mohon berikan izin clipboard pada browser.");
      });
    });
  });

  // Download JSON Button
  downloadJsonBtn.addEventListener("click", () => {
    chrome.storage.local.get(["scrapedVideos"], (result) => {
      const videos = result.scrapedVideos || [];
      if (videos.length === 0) {
        alert("Belum ada video ter-scrape untuk diunduh!");
        return;
      }

      const exportData = buildExportData(videos);
      const jsonStr = JSON.stringify(exportData, null, 2);
      const blob = new Blob([jsonStr], { type: "application/json" });
      const url = URL.createObjectURL(blob);

      const a = document.createElement("a");
      a.href = url;
      a.download = `lebahcuan_videos_${new Date().toISOString().slice(0,10)}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      showToast(`📥 Berhasil mengunduh ${exportData.length} video!`);
    });
  });

  // Clear Data Button
  clearBtn.addEventListener("click", () => {
    if (confirm("Yakin ingin menghapus seluruh cache video yang telah di-scrape? Tindakan ini tidak dapat dibatalkan.")) {
      chrome.storage.local.set({ scrapedVideos: [] }, () => {
        scrapedCount.textContent = "0";
        previewBadge.textContent = "0 Video";
        renderPreviewTable([]);
        showToast("🧹 Cache scraper berhasil dibersihkan!");
      });
    }
  });

  loadData();
});
